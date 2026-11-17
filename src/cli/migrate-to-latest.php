<?php
declare(strict_types=1);

/**
 * 一次性迁移：把任意历史版本的数据库迁到当前架构。
 *
 * 覆盖范围（全部向后兼容的历史变更，不需要逐个跑专项脚本）：
 *   1. 表：storage_profiles / audit_logs / content_hashes / api_stats / visit_stats / rate_limits
 *   2. 列与索引：images、users 的全部新增列与索引（来源 Application::schemaExpectations）
 *   3. 脏值：images.status 的 hidden/deleted → active 并收窄 ENUM
 *   4. 归属：images.storage / storage_provider 空值回填、storage_profile_id 按旧字段推断
 *   5. 冗余：清空 images.url（切到按存储实例解析后该列已无意义）
 *   6. 哈希：补 file_sha256，并把每条哈希写进 content_hashes 索引
 *   7. 退役 MD5：备份后 DROP images.file_hash 与 idx_file_hash
 *   8. 版本戳：settings.schema_version 置为当前值
 *
 * 用法: php src/cli/migrate-to-latest.php [--apply] [--force] [--skip-remote] [--limit=N] [--no-backup]
 *   默认干跑，只读不写。--apply 才执行，且每个阶段都幂等，可反复重跑。
 *   --force       仍有图片读不到字节时也删 MD5 列（这些图从此无法被判重命中）
 *   --skip-remote 不下载云端对象，只处理列内已有 SHA-256 的行
 *   --limit=N     本次最多回源 N 张，分批推进（未清零前不会删列）
 *   --no-backup   不导出 MD5 映射（不建议：删列后不可恢复）
 *
 * 顺序：先部署新代码，再跑本脚本。旧代码带 `WHERE file_hash = ?`，删列后会报 unknown column。
 */

use App\Core\Application;
use App\Core\Database;
use App\Models\ContentHash;
use App\Models\Setting;
use App\Models\StorageProfile;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args       = array_slice($argv, 1);
$apply      = in_array('--apply', $args, true);
$force      = in_array('--force', $args, true);
$skipRemote = in_array('--skip-remote', $args, true);
$noBackup   = in_array('--no-backup', $args, true);
$limit      = 0;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    }
}

require dirname(__DIR__) . '/bootstrap.php';

$tag = $apply ? '[执行]' : '[干跑]';
$fail = 0;

try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, '数据库连接失败（站点尚未安装？）: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** 统一的小工具：准备语句并执行，失败直接抛给调用方。 */
$q = static function (string $sql, array $params = []) use ($pdo): \PDOStatement {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
};

$tableExists = static function (string $table) use ($q): bool {
    return (int) $q(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    )->fetchColumn() > 0;
};
$columnExists = static function (string $table, string $column) use ($q): bool {
    return (int) $q(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    )->fetchColumn() > 0;
};
$indexExists = static function (string $table, string $index) use ($q): bool {
    return (int) $q(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$table, $index]
    )->fetchColumn() > 0;
};

echo str_repeat('=', 66) . PHP_EOL;
echo "MoeRNG 数据库一次性迁移 → 当前架构 {$tag}" . PHP_EOL;
echo str_repeat('=', 66) . PHP_EOL;

if (!$tableExists('images')) {
    fwrite(STDERR, 'images 表不存在 —— 站点尚未安装完成，先访问安装页。' . PHP_EOL);
    exit(1);
}

// ── 0. 盘点 ────────────────────────────────────────────────────────────────
$totalImages = (int) $q('SELECT COUNT(*) FROM `images`')->fetchColumn();
$hasMd5Col   = $columnExists('images', 'file_hash');
$missingSha  = (int) $q(
    "SELECT COUNT(*) FROM `images` WHERE `file_sha256` IS NULL OR `file_sha256` = ''"
)->fetchColumn();

echo PHP_EOL . '[0] 现状' . PHP_EOL;
printf("    图片总数: %d%s", $totalImages, PHP_EOL);
printf("    images.file_hash: %s%s", $hasMd5Col ? '存在（待退役）' : '已退役', PHP_EOL);
printf("    content_hashes 表: %s%s", ContentHash::tableExists() ? '存在' : '缺失（待创建）', PHP_EOL);
printf("    缺 file_sha256 的图片: %d%s", $missingSha, PHP_EOL);

// ── 1. 架构迁移 ────────────────────────────────────────────────────────────
echo PHP_EOL . '[1] 架构迁移（表 / 列 / 索引 / status 收敛）' . PHP_EOL;

$schemaBefore = [
    'storage_profiles' => $tableExists('storage_profiles'),
    'audit_logs'       => $tableExists('audit_logs'),
    'content_hashes'   => $tableExists('content_hashes'),
    'api_stats'        => $tableExists('api_stats'),
    'visit_stats'      => $tableExists('visit_stats'),
    'rate_limits'      => $tableExists('rate_limits'),
];

$exp = Application::schemaExpectations();
$missingCols = 0;
$missingIdx = 0;
foreach ($exp['columns'] as $table => $cols) {
    foreach ($cols as $col => $def) {
        if (!$columnExists($table, $col)) {
            $missingCols++;
            echo "    + {$table}.{$col}" . PHP_EOL;
        }
    }
}
foreach ($exp['indexes'] as $table => $defs) {
    foreach ($defs as $name => $frag) {
        if (!$indexExists($table, $name)) {
            $missingIdx++;
            echo "    + 索引 {$table}.{$name}" . PHP_EOL;
        }
    }
}
foreach ($schemaBefore as $table => $present) {
    if (!$present) {
        echo "    + 表 {$table}" . PHP_EOL;
    }
}

if ($apply) {
    $schemaError = Application::migrateSchema($pdo);
    if ($schemaError !== '') {
        fwrite(STDERR, '    迁移失败: ' . $schemaError . PHP_EOL);
        fwrite(STDERR, '    修掉上面的原因后重跑；本脚本幂等。' . PHP_EOL);
        exit(1);
    }
    echo '    已同步到当前架构' . PHP_EOL;
} elseif ($missingCols === 0 && $missingIdx === 0 && !in_array(false, $schemaBefore, true)) {
    echo '    已是当前架构，无需补齐' . PHP_EOL;
}

// ── 2. 历史兼容数据 ────────────────────────────────────────────────────────
echo PHP_EOL . '[2] 历史兼容数据' . PHP_EOL;

// 2.1 存储归属：没有 profile_id 的老行按 storage / provider 推断
$noProfile = (int) $q('SELECT COUNT(*) FROM `images` WHERE `storage_profile_id` IS NULL')->fetchColumn();
$unmatched = [];
$profilePlan = [];
$spReady = $tableExists('storage_profiles');
if ($noProfile > 0 && !$spReady) {
    echo '    存储实例表尚未创建（第 1 阶段会建），本次无法推断归属' . PHP_EOL;
} elseif ($noProfile > 0) {
    $rows = $q(
        'SELECT `id`, `path`, `storage`, `storage_provider` FROM `images`'
        . ' WHERE `storage_profile_id` IS NULL ORDER BY `id` ASC'
    )->fetchAll();
    foreach ($rows as $r) {
        $target = StorageProfile::resolveLegacy(
            (string) ($r['storage'] ?? 'local'),
            (string) ($r['storage_provider'] ?? '')
        );
        if ($target === null) {
            $unmatched[] = $r;
        } else {
            $profilePlan[(int) $r['id']] = (int) $target->id;
        }
    }
}
printf("    缺存储归属: %d（可推断 %d，无法推断 %d）%s",
    $noProfile, count($profilePlan), count($unmatched), PHP_EOL);
foreach (array_slice($unmatched, 0, 5) as $r) {
    printf("      ⚠ #%d storage=%s provider=%s 无匹配实例%s",
        (int) $r['id'], (string) $r['storage'], (string) $r['storage_provider'], PHP_EOL);
}
if (count($unmatched) > 5) {
    printf("      …（另有 %d 行）%s", count($unmatched) - 5, PHP_EOL);
}

if ($apply && $profilePlan !== []) {
    $upd = $pdo->prepare('UPDATE `images` SET `storage_profile_id` = ? WHERE `id` = ? AND `storage_profile_id` IS NULL');
    $pdo->beginTransaction();
    try {
        foreach ($profilePlan as $id => $pid) {
            $upd->execute([$pid, $id]);
        }
        $pdo->commit();
        printf("    → 已回填 %d 行存储归属%s", count($profilePlan), PHP_EOL);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, '    回填失败（已回滚）: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
if ($unmatched !== []) {
    $fail++;
    echo '    ⚠ 这些行没有可匹配的存储实例：到「存储管理」补建实例后重跑，或删除这些图片记录。' . PHP_EOL;
}

// 2.2 url 列已无意义（读取一律按存储实例解析）
$urlLeft = (int) $q("SELECT COUNT(*) FROM `images` WHERE `url` IS NOT NULL AND `url` <> ''")->fetchColumn();
printf("    残留 url: %d 行%s", $urlLeft, PHP_EOL);
if ($apply && $urlLeft > 0) {
    $cleared = (int) $pdo->exec("UPDATE `images` SET `url` = '' WHERE `url` <> ''");
    printf("    → 已清空 %d 行%s", $cleared, PHP_EOL);
}

// ── 3. 内容哈希回填 ────────────────────────────────────────────────────────
echo PHP_EOL . '[3] 内容哈希回填（file_sha256 + content_hashes）' . PHP_EOL;

$rows = $q('SELECT `id`, `path`, `storage_profile_id`, `file_sha256` FROM `images` ORDER BY `id` ASC')->fetchAll();
$known = [];
if (ContentHash::tableExists()) {
    foreach ($q('SELECT `sha256` FROM `content_hashes` WHERE `image_id` IS NOT NULL')->fetchAll() as $r) {
        $known[(string) $r['sha256']] = true;
    }
}

$covered = 0;
$toIndex = 0;
$needRead = 0;
foreach ($rows as $row) {
    $sha = trim((string) ($row['file_sha256'] ?? ''));
    if ($sha !== '' && ContentHash::isHash($sha)) {
        isset($known[$sha]) ? $covered++ : $toIndex++;
        continue;
    }
    $needRead++;
}
printf("    列内哈希已索引: %d；待写索引: %d；需回源: %d%s", $covered, $toIndex, $needRead, PHP_EOL);

$reindexed = 0;
$recomputed = 0;
$readFail = [];

if ($apply) {
    foreach ($rows as $row) {
        $sha = trim((string) ($row['file_sha256'] ?? ''));
        if ($sha === '' || !ContentHash::isHash($sha) || isset($known[$sha])) {
            continue;
        }
        try {
            ContentHash::record((int) $row['id'], [ContentHash::KIND_STORED => $sha]);
            $known[$sha] = true;
            $reindexed++;
        } catch (\Throwable $e) {
            $readFail[(int) $row['id']] = '写索引失败: ' . $e->getMessage();
        }
    }

    $budget = $limit > 0 ? min($needRead, $limit) : $needRead;
    $tried = 0;
    foreach ($rows as $row) {
        if ($tried >= $budget) {
            break;
        }
        $sha = trim((string) ($row['file_sha256'] ?? ''));
        if ($sha !== '' && ContentHash::isHash($sha)) {
            continue;
        }
        $id = (int) $row['id'];
        $tried++;   // 预算是「尝试多少张」，读失败同样占额，避免全失败时反复超时
        $res = ContentHash::sha256ForImageRow($row, !$skipRemote);
        if (!$res['ok']) {
            $readFail[$id] = (string) ($res['error'] ?? '未知错误');
            continue;
        }
        $newSha = (string) $res['sha'];
        $pdo->prepare('UPDATE `images` SET `file_sha256` = ? WHERE `id` = ?')->execute([$newSha, $id]);
        ContentHash::record($id, [ContentHash::KIND_STORED => $newSha]);
        $known[$newSha] = true;
        $recomputed++;
    }

    if ($reindexed + $recomputed > 0) {
        printf("    → 写入索引 %d 条（列内 %d，回源 %d）%s",
            $reindexed + $recomputed, $reindexed, $recomputed, PHP_EOL);
    }
    if ($readFail !== []) {
        printf("    ⚠ 回源失败 %d 张，示例：%s", count($readFail), PHP_EOL);
        $shown = 0;
        foreach ($readFail as $id => $why) {
            if ($shown++ >= 5) {
                break;
            }
            printf("      #%d %s%s", $id, $why, PHP_EOL);
        }
    }
} elseif ($needRead > 0) {
    echo '    （干跑不下载对象；加 --apply 才会回源计算）' . PHP_EOL;
}

// ── 4. 索引体检 ────────────────────────────────────────────────────────────
echo PHP_EOL . '[4] 索引体检' . PHP_EOL;

if (ContentHash::tableExists()) {
    $dangling = (int) $q(
        'SELECT COUNT(*) FROM `content_hashes` ch LEFT JOIN `images` i ON i.`id` = ch.`image_id`'
        . ' WHERE ch.`image_id` IS NOT NULL AND i.`id` IS NULL'
    )->fetchColumn();
    $stale = (int) $q(
        'SELECT COUNT(*) FROM `content_hashes` WHERE `image_id` IS NULL'
        . ' AND `created_at` < (NOW() - INTERVAL 10 MINUTE)'
    )->fetchColumn();
    printf("    悬空索引（指向已删除图片）: %d%s", $dangling, PHP_EOL);
    printf("    超时占位（请求中途死亡遗留）: %d%s", $stale, PHP_EOL);
    if ($apply) {
        if ($dangling > 0) {
            $removed = (int) $pdo->exec(
                'DELETE ch FROM `content_hashes` ch LEFT JOIN `images` i ON i.`id` = ch.`image_id`'
                . ' WHERE ch.`image_id` IS NOT NULL AND i.`id` IS NULL'
            );
            printf("    → 删除悬空索引 %d 条%s", $removed, PHP_EOL);
        }
        if ($stale > 0) {
            printf("    → 回收超时占位 %d 条%s", ContentHash::sweepStale(10), PHP_EOL);
        }
    }
} else {
    echo '    索引表缺失，跳过（第 1 阶段会创建）' . PHP_EOL;
}

// ── 5. 门禁 ────────────────────────────────────────────────────────────────
echo PHP_EOL . '[5] 门禁检查' . PHP_EOL;

$stillMissing = (int) $q(
    "SELECT COUNT(*) FROM `images` WHERE `file_sha256` IS NULL OR `file_sha256` = ''"
)->fetchColumn();
printf("    仍缺 SHA-256: %d / %d%s", $stillMissing, $totalImages, PHP_EOL);

$apiKeyPlain = $tableExists('api_keys') && !$columnExists('api_keys', 'key_hash');

if (!$hasMd5Col) {
    echo '    MD5 列已不存在 —— 架构已是终态，无需再退役' . PHP_EOL;
} elseif ($stillMissing > 0 && !$force) {
    echo PHP_EOL;
    echo '    拒绝删 MD5 列：仍有 ' . $stillMissing . ' 张图片没有 SHA-256。' . PHP_EOL;
    echo '    删掉 MD5 后这些图片既不会被判重命中，也失去内容指纹。' . PHP_EOL;
    echo '      · 补齐后再跑：  php src/cli/migrate-to-latest.php --apply' . PHP_EOL;
    echo '      · 远端不可达时分批：php src/cli/migrate-to-latest.php --apply --limit=100' . PHP_EOL;
    echo '      · 确认可放弃这些图的内容指纹：加 --force' . PHP_EOL;
    if ($apiKeyPlain) {
        echo PHP_EOL . '    另：api_keys 仍存明文 key，需单独跑 php src/cli/migrate-apikey-hash.php --apply' . PHP_EOL;
    }
    exit(2);
}

// ── 6. 备份 MD5 映射 ───────────────────────────────────────────────────────
$backupFile = '';
if ($hasMd5Col) {
    echo PHP_EOL . '[6] 备份 MD5 映射' . PHP_EOL;
    if ($noBackup) {
        echo '    已按 --no-backup 跳过（删列后不可恢复）' . PHP_EOL;
    } else {
        $dir = dirname(__DIR__) . '/storage/backups';
        $backupFile = $dir . '/images-md5-' . date('Ymd-His') . '.jsonl';
        if (!$apply) {
            echo "    将写入 {$backupFile}" . PHP_EOL;
        } else {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                fwrite(STDERR, '    备份目录不可写，已中止（确需跳过请加 --no-backup）' . PHP_EOL);
                exit(1);
            }
            $fh = @fopen($backupFile, 'wb');
            if ($fh === false) {
                fwrite(STDERR, '    备份文件无法创建，已中止（确需跳过请加 --no-backup）' . PHP_EOL);
                exit(1);
            }
            $saved = 0;
            $cur = $q("SELECT `id`, `file_hash` FROM `images` WHERE `file_hash` IS NOT NULL AND `file_hash` <> ''");
            while ($r = $cur->fetch()) {
                fwrite($fh, json_encode(
                    ['id' => (int) $r['id'], 'file_hash' => rtrim((string) $r['file_hash'])],
                    JSON_UNESCAPED_SLASHES
                ) . PHP_EOL);
                $saved++;
            }
            fclose($fh);
            printf("    %d 条 → %s%s", $saved, $backupFile, PHP_EOL);
        }
    }
}

// ── 7. 退役 MD5 列 ─────────────────────────────────────────────────────────
if ($hasMd5Col) {
    echo PHP_EOL . '[7] 退役 MD5 列' . PHP_EOL;
    if ($indexExists('images', 'idx_file_hash')) {
        echo '    - DROP INDEX idx_file_hash' . PHP_EOL;
        if ($apply) {
            try {
                $pdo->exec('ALTER TABLE `images` DROP INDEX `idx_file_hash`');
            } catch (\Throwable $e) {
                fwrite(STDERR, '    删索引失败: ' . $e->getMessage() . PHP_EOL);
                exit(1);
            }
        }
    } else {
        echo '    idx_file_hash 不存在，跳过' . PHP_EOL;
    }

    echo '    - DROP COLUMN file_hash' . PHP_EOL;
    if ($apply) {
        try {
            // 大表上 DROP COLUMN 可能重建表；限定等锁时间，避免把线上写请求堵死
            $pdo->exec('SET SESSION lock_wait_timeout = 10');
            $pdo->exec('ALTER TABLE `images` DROP COLUMN `file_hash`');
        } catch (\Throwable $e) {
            fwrite(STDERR, '    删列失败: ' . $e->getMessage() . PHP_EOL);
            fwrite(STDERR, '    修掉原因后重跑；本脚本幂等。' . PHP_EOL);
            exit(1);
        }
    }
}

// ── 8. 版本戳 ──────────────────────────────────────────────────────────────
echo PHP_EOL . '[8] 版本戳' . PHP_EOL;
$currentVersion = Setting::get('schema_version', '');
$targetVersion = Application::schemaVersion();
printf("    当前: %s；目标: %s%s", $currentVersion === '' ? '(空)' : $currentVersion, $targetVersion, PHP_EOL);
if ($apply) {
    Setting::set('schema_version', $targetVersion);
    if (Setting::get('migration_last_error', '') !== '') {
        Setting::set('migration_last_error', '');
    }
    echo '    已写入（运行时启动时的自迁移将整体跳过）' . PHP_EOL;
}

// ── 9. 终态校验 ────────────────────────────────────────────────────────────
echo PHP_EOL . '[9] 终态校验' . PHP_EOL;

if (!$apply) {
    echo '    干跑结束，未写入任何数据。确认后执行：' . PHP_EOL;
    echo '      php src/cli/migrate-to-latest.php --apply' . PHP_EOL;
    echo PHP_EOL . '  注意：先部署新代码再执行 —— 旧代码的 WHERE file_hash = ? 在删列后会报错。' . PHP_EOL;
    exit(0);
}

$problems = [];

foreach (['settings', 'users', 'api_keys', 'categories', 'images', 'storage_profiles', 'rate_limits',
          'audit_logs', 'content_hashes', 'api_stats', 'visit_stats'] as $t) {
    if (!$tableExists($t)) {
        $problems[] = "缺表 {$t}";
    }
}
foreach ($exp['columns'] as $table => $cols) {
    foreach ($cols as $col => $def) {
        if (!$columnExists($table, $col)) {
            $problems[] = "缺列 {$table}.{$col}";
        }
    }
}
foreach ($exp['indexes'] as $table => $defs) {
    foreach ($defs as $name => $frag) {
        if (!$indexExists($table, $name)) {
            $problems[] = "缺索引 {$table}.{$name}";
        }
    }
}
if ($columnExists('images', 'file_hash')) {
    $problems[] = 'images.file_hash 仍在';
}
if ($indexExists('images', 'idx_file_hash')) {
    $problems[] = 'idx_file_hash 仍在';
}
if (!$indexExists('images', 'idx_file_sha256')) {
    $problems[] = '缺 idx_file_sha256（判重查询会全表扫描）';
}

$noProfileLeft = (int) $q('SELECT COUNT(*) FROM `images` WHERE `storage_profile_id` IS NULL')->fetchColumn();
if ($noProfileLeft > 0) {
    $problems[] = "{$noProfileLeft} 张图仍缺 storage_profile_id";
}
$urlLeft = (int) $q("SELECT COUNT(*) FROM `images` WHERE `url` IS NOT NULL AND `url` <> ''")->fetchColumn();
if ($urlLeft > 0) {
    $problems[] = "{$urlLeft} 行 url 未清空";
}
$deadStatus = 0;
try {
    $deadStatus = (int) $q("SELECT COUNT(*) FROM `images` WHERE `status` <> 'active'")->fetchColumn();
} catch (\Throwable) {
    // ENUM 已收窄时该比较仍合法；异常只说明库版本差异
}
if ($deadStatus > 0) {
    $problems[] = "{$deadStatus} 行 status 非 active";
}
if ($stillMissing > 0) {
    $problems[] = "{$stillMissing} 张图缺 SHA-256（不会被判重命中）";
}
$unindexed = (int) $q(
    'SELECT COUNT(*) FROM `images` i LEFT JOIN `content_hashes` ch'
    . ' ON ch.`sha256` = i.`file_sha256` AND ch.`image_id` = i.`id`'
    . " WHERE TRIM(COALESCE(i.`file_sha256`, '')) <> '' AND ch.`sha256` IS NULL"
)->fetchColumn();
if ($unindexed > 0) {
    $problems[] = "{$unindexed} 张图有哈希但未进索引";
}
if (Setting::get('schema_version', '') !== $targetVersion) {
    $problems[] = 'schema_version 未写为 ' . $targetVersion;
}

$indexedRows = (int) $q('SELECT COUNT(*) FROM `content_hashes` WHERE `image_id` IS NOT NULL')->fetchColumn();
printf("    内容哈希索引: %d 条（库内 %d 张图）%s", $indexedRows, $totalImages, PHP_EOL);
printf("    删除的 MD5 备份: %s%s", $backupFile === '' ? '（无）' : $backupFile, PHP_EOL);

if ($problems !== []) {
    echo PHP_EOL . '    未全绿：' . PHP_EOL;
    foreach ($problems as $p) {
        echo '      · ' . $p . PHP_EOL;
    }
    echo PHP_EOL . '    按上面逐项处理后重跑本脚本（幂等）。' . PHP_EOL;
    exit(1);
}

if ($apiKeyPlain) {
    echo PHP_EOL . '    ⚠ api_keys 仍存明文 key —— 单独运行：php src/cli/migrate-apikey-hash.php --apply' . PHP_EOL;
}

echo PHP_EOL . '迁移完成：数据库已处于当前架构。' . PHP_EOL;
echo '  · 后台「系统设置 → 健康检查」应全绿（去重判据 / 图片哈希 / 历史兼容数据）' . PHP_EOL;
echo '  · 判重统一走 content_hashes，删图经外键级联释放哈希，删完可重新上传同一张' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
