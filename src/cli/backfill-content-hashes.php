<?php
declare(strict_types=1);

/**
 * 为存量图片补建内容哈希索引（content_hashes），补完后判重不再依赖 MD5 兜底。
 * 用法: php src/cli/backfill-content-hashes.php [--apply] [--limit=N]；默认干跑，幂等
 */

use App\Core\Database;
use App\Models\ContentHash;
use App\Models\StorageProfile;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$argvRest = array_slice($argv, 1);
$apply = in_array('--apply', $argvRest, true);
$limit = 0;
foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    }
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, '数据库连接失败（站点尚未安装？）: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

ContentHash::ensureTable($pdo);

$indexed = [];
foreach ($pdo->query('SELECT `sha256` FROM `content_hashes` WHERE `image_id` IS NOT NULL')
    ->fetchAll(\PDO::FETCH_COLUMN) as $h) {
    $indexed[(string) $h] = true;
}

$rows = $pdo->query(
    'SELECT `id`, `path`, `file_sha256`, `storage_profile_id` FROM `images` ORDER BY `id` ASC'
)->fetchAll(\PDO::FETCH_ASSOC);

$plan = [];        // id => sha256（来自列，无需读盘）
$needRead = [];    // 需要回源算哈希的行
$already = 0;
foreach ($rows as $row) {
    $sha = trim((string) ($row['file_sha256'] ?? ''));
    if ($sha !== '' && isset($indexed[$sha])) {
        $already++;
        continue;
    }
    if ($sha !== '') {
        $plan[(int) $row['id']] = $sha;
    } else {
        $needRead[] = $row;
    }
}

if ($limit > 0) {
    $plan = array_slice($plan, 0, $limit, true);
    $needRead = array_slice($needRead, 0, $limit);
}

$readOk = 0;
$readFailed = 0;
$computed = [];
foreach ($needRead as $row) {
    $id = (int) $row['id'];
    $path = (string) $row['path'];
    $localFile = null;
    $isTemp = false;
    try {
        $profile = StorageProfile::resolve(
            $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
        );
        if ($profile === null) {
            $readFailed++;
            continue;
        }
        $driver = StorageProfile::cachedDriver($profile);
        if ($driver instanceof \App\Storage\LocalDriver) {
            $candidate = $driver->uploadDir() . '/' . ltrim($path, '/');
            if (!is_file($candidate) || !is_readable($candidate)) {
                $readFailed++;
                continue;
            }
            $localFile = $candidate;
        } else {
            $localFile = \App\Storage\S3Driver::downloadUrl($driver->url($path));
            $isTemp = $localFile !== null;
            if ($localFile === null) {
                $readFailed++;
                continue;
            }
        }
        $sha = @hash_file('sha256', $localFile);
        if ($isTemp) {
            @unlink($localFile);
        }
        if (!is_string($sha) || $sha === '') {
            $readFailed++;
            continue;
        }
        $readOk++;
        $computed[$id] = $sha;
    } catch (\Throwable) {
        if ($isTemp && is_string($localFile)) {
            @unlink($localFile);
        }
        $readFailed++;
    }
}

$total = count($plan) + count($computed);
echo '已索引: ' . $already . PHP_EOL;
echo '待补建: ' . $total . '（列内已有 ' . count($plan) . '，需回源读 ' . count($computed) . '）' . PHP_EOL;
echo '回源失败: ' . $readFailed . PHP_EOL;

if ($total === 0) {
    echo "没有需要补建的图片。\n";
    exit(0);
}

if (!$apply) {
    $preview = 0;
    foreach ($plan + $computed as $id => $sha) {
        if ($preview++ >= 10) {
            break;
        }
        echo "  would index #{$id} => {$sha}" . PHP_EOL;
    }
    $swept = ContentHash::sweepStale(10);
    echo "干跑结束（回收超时占位 {$swept} 条）。加 --apply 写入。\n";
    exit(0);
}

$done = 0;
$fail = 0;
foreach ($plan as $id => $sha) {
    try {
        ContentHash::record($id, [ContentHash::KIND_STORED => $sha]);
        $done++;
    } catch (\Throwable $e) {
        $fail++;
        fwrite(STDERR, "#{$id} 写入失败: " . $e->getMessage() . PHP_EOL);
    }
}
foreach ($computed as $id => $sha) {
    try {
        ContentHash::record($id, [ContentHash::KIND_STORED => $sha]);
        // 顺手把空列的强哈希补上，下次运行不必再回源
        $pdo->prepare('UPDATE `images` SET `file_sha256` = ? WHERE `id` = ? AND (file_sha256 IS NULL OR file_sha256 = \'\')')
            ->execute([$sha, $id]);
        $done++;
    } catch (\Throwable $e) {
        $fail++;
        fwrite(STDERR, "#{$id} 写入失败: " . $e->getMessage() . PHP_EOL);
    }
}

$swept = ContentHash::sweepStale(10);
echo "完成：索引 {$done} 条，失败 {$fail} 条，回收超时占位 {$swept} 条。\n";
