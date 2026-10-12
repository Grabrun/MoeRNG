<?php
declare(strict_types=1);

/**
 * MoeRNG - API Key 明文落库迁移工具（MR-03）
 *
 * 背景：旧版 api_keys 表把完整明文 Key 存在 `key` 列（CWE-312）。本次改为
 * 只存 SHA-256 哈希（key_hash）+ 16 位展示前缀（key_prefix），与 users 表
 * remember_token 同一模式。本脚本负责把旧库里的存量明文行升级为新结构。
 *
 * 用法（部署机 src/ 同级目录下执行）：
 *   php src/cli/migrate-apikey-hash.php            # dry-run（默认，只读，不落任何数据）
 *   php src/cli/migrate-apikey-hash.php --apply    # 真正迁移
 *
 * 行为：
 *   - dry-run：打印存量明文行数与受影响清单（id / name / 旧 Key 前 16 位预览 /
 *     status）。清单中【绝不】打印完整明文 Key，也不写报告文件。
 *   - --apply：逐行读出旧明文 Key → 生成一枚全新 Key → 落库 key_hash +
 *     key_prefix（前缀 = 新 Key 前 16 位）→ 清空旧 `key` 列（移除 at-rest 明文）。
 *     旧 Key 随即失效（鉴权只按 key_hash 反查，且旧列已清空）。
 *   - 全新 Key 的明文【仅】写入迁移报告文件（src/var/apikey-migration-<时间戳>.txt），
 *     报告文件 web 不可达且已被 .gitignore 忽略；执行结束时打印其绝对路径。
 *     每行格式：id=<id>\tname=<name>\tnew_key=<完整新明文>。旧明文不会写入报告。
 *   - 幂等：已迁移行（key_prefix 非空 且 key_hash 为 64 位 hex）自动跳过；
 *     重复执行 --apply 只会处理剩余明文行；无明文行时输出 0 并正常退出。
 *
 * 注意：升级部署后、运行本工具前，所有存量旧 Key 都已无法通过鉴权
 * （新代码按 key_hash 反查，而旧行 key_hash 为空）——这是预期行为。
 */

use App\Core\Database;

$apply = in_array('--apply', array_slice($argv, 1), true);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

/** @return array<string, true> 表上现有列名集合 */
$columns = static function (PDO $pdo): array {
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'api_keys\''
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $out[(string) $col] = true;
    }
    return $out;
};

$cols = $columns($pdo);

// 新结构站点：根本没有旧 `key` 列，无需迁移。
if (!isset($cols['key'])) {
    echo "未检测到旧 `key` 列（已是新结构或全新安装），无需迁移。\n";
    exit(0);
}

$hasHashCols = isset($cols['key_hash'], $cols['key_prefix']);

// 拼装「存量明文行」查询：新列已存在时，已迁移行（前缀非空 + 哈希为 64hex）跳过。
if ($hasHashCols) {
    $sql = 'SELECT id, name, `key`, status FROM `api_keys`
            WHERE `key` IS NOT NULL AND `key` <> \'\'
              AND NOT (key_prefix <> \'\' AND key_hash REGEXP \'^[0-9a-f]{64}$\')';
} else {
    // 升级第一步：新列还没建，所有带明文 `key` 的行都是待迁移行。
    $sql = 'SELECT id, name, `key`, status FROM `api_keys`
            WHERE `key` IS NOT NULL AND `key` <> \'\'';
}

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$count = count($rows);

echo sprintf('待迁移明文 Key 行数：%d%s', $count, "\n");

if ($count === 0) {
    echo "没有需要迁移的行。\n";
    exit(0);
}

// dry-run：只列清单（旧 Key 前 16 位预览），不写任何东西。
echo str_repeat('-', 60) . "\n";
foreach ($rows as $r) {
    echo sprintf(
        "  id=%-5d name=%-30s preview=%s...  status=%s\n",
        (int) $r['id'],
        mb_substr((string) $r['name'], 0, 30),
        substr((string) $r['key'], 0, 16),
        (string) $r['status']
    );
}
echo str_repeat('-', 60) . "\n";

if (!$apply) {
    echo "dry-run 结束（未改动任何数据）。确认无误后执行：\n";
    echo "  php src/cli/migrate-apikey-hash.php --apply\n";
    exit(0);
}

// ── apply 模式 ─────────────────────────────────────────────────────────

// 若新列尚不存在（升级首次执行），幂等地补列 + 索引。
if (!$hasHashCols) {
    echo "检测到旧表结构，正在补充 key_hash / key_prefix 列...\n";
    $pdo->exec(
        "ALTER TABLE `api_keys`
         ADD COLUMN `key_hash` CHAR(64) NULL DEFAULT NULL,
         ADD COLUMN `key_prefix` VARCHAR(24) NOT NULL DEFAULT ''"
    );
    $idx = $pdo->query("SHOW INDEX FROM `api_keys` WHERE Key_name = 'idx_key_hash'")->fetch();
    if (!$idx) {
        $pdo->exec("ALTER TABLE `api_keys` ADD INDEX `idx_key_hash` (`key_hash`)");
    }
}

$varDir = dirname(__DIR__) . '/var';
if (!is_dir($varDir) && !mkdir($varDir, 0775, true) && !is_dir($varDir)) {
    fwrite(STDERR, "无法创建报告目录: {$varDir}\n");
    exit(1);
}
$reportPath = $varDir . '/apikey-migration-' . date('Ymd_His') . '.txt';

$reportLines = [];
$reportLines[] = 'MoeRNG API Key migration report (MR-03)';
$reportLines[] = 'time=' . date('c');
$reportLines[] = 'rows=' . $count;
$reportLines[] = 'NOTE: new plaintext keys below are shown THIS TIME ONLY. '
    . 'Distribute them and delete this file afterwards.';
$reportLines[] = str_repeat('-', 60);

$updateStmt = $pdo->prepare(
    'UPDATE `api_keys`
     SET `key_hash` = :h, `key_prefix` = :p, `key` = \'\'
     WHERE `id` = :id'
);

$migrated = 0;
foreach ($rows as $r) {
    $id = (int) $r['id'];
    $newPlain = \App\Models\ApiKey::generateKey();

    $updateStmt->execute([
        ':id' => $id,
        ':h'  => \App\Models\ApiKey::hashKey($newPlain),
        ':p'  => substr($newPlain, 0, 16),
    ]);

    // 报告只记录新明文；旧明文绝不落盘。
    $reportLines[] = sprintf(
        'id=%d	name=%s	new_key=%s',
        $id,
        str_replace("\t", ' ', (string) $r['name']),
        $newPlain
    );
    $migrated++;
    echo sprintf("  已迁移 id=%d（%s）\n", $id, (string) $r['name']);
}

file_put_contents($reportPath, implode("\n", $reportLines) . "\n", LOCK_EX);

echo str_repeat('-', 60) . "\n";
echo sprintf("迁移完成：%d 行。旧 Key 已全部失效（旧 `key` 列已清空，鉴权仅按 key_hash 反查）。\n", $migrated);
echo "新 Key 明文仅记录于报告文件（web 不可达、gitignored）：\n";
echo '  ' . $reportPath . "\n";
echo "请逐枚分发报告中的 new_key 给对应调用方；分发完成后建议删除该报告文件。\n";
