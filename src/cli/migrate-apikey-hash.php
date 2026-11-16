<?php
declare(strict_types=1);

/**
 * API Key 明文落库一次性迁移（改为只存 key_hash + key_prefix）。
 * 用法: php src/cli/migrate-apikey-hash.php [--apply]；默认干跑只读
 * 安全：新 Key 明文仅写入 src/var 报告文件（web 不可达），分发后应删除该文件。
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

if (!isset($cols['key'])) {
    echo "未检测到旧 `key` 列（已是新结构或全新安装），无需迁移。\n";
    exit(0);
}

$hasHashCols = isset($cols['key_hash'], $cols['key_prefix']);

// 已迁移行（前缀非空 + key_hash 为 64hex）跳过。
if ($hasHashCols) {
    $sql = 'SELECT id, name, `key`, status FROM `api_keys`
            WHERE `key` IS NOT NULL AND `key` <> \'\'
              AND NOT (key_prefix <> \'\' AND key_hash REGEXP \'^[0-9a-f]{64}$\')';
} else {
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
