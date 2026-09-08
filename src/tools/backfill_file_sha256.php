<?php
declare(strict_types=1);

/**
 * v1.3.2 迭代: 存量图片 哈希回填脚本（一次性、幂等、可重复执行）。
 *
 * 用途: 为历史图片补齐 file_hash（MD5）+ file_sha256（SHA-256）。分层校验上线后
 * 新上传会同时写这两个字段; 存量旧图可能只有其一或皆无（此前 fillable 缺失导致
 * file_hash 从未落库）。补全后 "MD5 命中 → SHA-256 精确比对" 即可零流量直比。
 *
 * 关键正确性要求:
 *  - 对象存储: 【拉取到临时文件夹】，用 hash_file 针对同一份字节算 MD5 + SHA-256，
 *    结束后删除临时文件。不流失算 —— 确保两个哈希来自同一对象、互不混淆。
 *  - 本地存储: 直接 hash_file 读取（无需拷贝）。
 *  - 幂等: 已含 file_sha256 的行跳过; 只补缺失的字段（MD5 缺失补 MD5，SHA-256
 *    缺失补 SHA-256），不覆盖已有值。
 *
 * 用法:
 *   php src/tools/backfill_file_sha256.php [--limit=N] [--stop-on-error]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "只能以 CLI 运行：php src/tools/backfill_file_sha256.php\n");
    exit(1);
}

$root = dirname(__DIR__, 2);          // .../src
require_once $root . '/app/Autoloader.php';
\App\Autoloader::register($root . '/app');

use App\Models\Image;
use App\Models\StorageProfile;
use App\Storage\LocalDriver;
use App\Storage\S3Driver;

$limit = null;
$stopOnError = false;
foreach ($argv as $i => $a) {
    if ($a === '--limit' && isset($argv[$i + 1])) {
        $limit = max(1, (int) $argv[$i + 1]);
    }
    if ($a === '--stop-on-error') {
        $stopOnError = true;
    }
}

echo "=== 存量图片 哈希回填（对象存储拉临时文件算 MD5+SHA-256）===\n";

$rows = Image::all('id ASC');
$total = count($rows);
echo "待处理图片总数: {$total}\n";
if ($total === 0) {
    echo "完成（无数据）。\n";
    exit(0);
}

$processed = 0;
$updated = 0;
$skippedNoStorage = 0;
$failed = 0;
$already = 0;
$tmpDir = sys_get_temp_dir();

foreach ($rows as $image) {
    $hasSha = (string) ($image->file_sha256 ?? '') !== '';
    $hasMd5 = (string) ($image->file_hash ?? '') !== '';

    // 幂等：两者都有则跳过
    if ($hasSha && $hasMd5) {
        $already++;
        continue;
    }
    if ($limit !== null && $processed >= $limit) {
        echo "已到 --limit={$limit}，提前停止。\n";
        break;
    }
    $processed++;

    $id = (int) $image->id;
    $path = (string) $image->path;
    $driverName = (string) $image->storage;

    try {
        $profile = StorageProfile::defaultProfile();
        if ($profile === null) {
            $skippedNoStorage++;
            echo "  [{$id}] 无可用存储实例，跳过（driver={$driverName}）\n";
            continue;
        }
        $driver = $profile->driver();

        // —— 取字节到本地，统一用 hash_file 算双哈希 ——
        $localFile = null;
        if ($driver instanceof LocalDriver) {
            // 本地存储：直接指向文件（不拷贝，读后即弃）
            $localFile = $driver->uploadDir() . '/' . ltrim($path, '/');
            if (!is_file($localFile) || !is_readable($localFile)) {
                $failed++;
                echo "  [{$id}] 本地文件不可读（{$localFile}）\n";
                if ($stopOnError) exit(1);
                continue;
            }
        } else {
            // 对象存储：拉取到临时文件夹
            $url = $driver->url($path);
            $localFile = S3Driver::downloadUrl($url, $tmpDir);
            if ($localFile === null) {
                $failed++;
                echo "  [{$id}] 对象拉取失败（path={$path}）\n";
                if ($stopOnError) exit(1);
                continue;
            }
        }

        // —— 算双哈希 ——
        $md5 = @hash_file('md5', $localFile);
        $sha = @hash_file('sha256', $localFile);

        // 对象存储：用完即删临时文件
        if (!($driver instanceof LocalDriver)) {
            @unlink($localFile);
        }

        if ($md5 === false || $sha === false || $md5 === '' || $sha === '') {
            $failed++;
            echo "  [{$id}] 哈希计算失败\n";
            if ($stopOnError) exit(1);
            continue;
        }

        // —— 回填（只填缺失字段，不覆盖已有）——
        if (!$hasMd5) { $image->file_hash = $md5; }
        if (!$hasSha) { $image->file_sha256 = $sha; }

        if ($image->save()) {
            $updated++;
            echo "  [{$id}] 回填 MD5=" . substr($md5, 0, 8) . "… SHA-256=" . substr($sha, 0, 12) . "…\n";
        } else {
            $failed++;
            echo "  [{$id}] 保存失败\n";
            if ($stopOnError) exit(1);
        }
    } catch (\Throwable $e) {
        $failed++;
        echo "  [{$id}] 异常: {$e->getMessage()}\n";
        if ($stopOnError) exit(1);
    }
}

echo "=== 完成 ===\n";
echo "已回填: {$updated}\n";
echo "跳过(无存储实例): {$skippedNoStorage}\n";
echo "失败: {$failed}\n";
echo "已有(无需处理): {$already}\n";
