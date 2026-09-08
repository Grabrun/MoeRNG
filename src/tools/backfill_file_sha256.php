<?php
declare(strict_types=1);

/**
 * v1.3.2 迭代: 存量图片 SHA-256 回填脚本（一次性、幂等、可重复执行）。
 *
 * 背景: 分层校验（MD5 快速初筛 + SHA-256 二次确认）后，新上传写入 file_hash（MD5）
 * + file_sha256（SHA-256）。本项目历史图片可能只有 file_hash、或两者皆无
 * （此前 file_hash 因 Image::$fillable 缺失从未真正落库）。
 *
 * 作用: 遍历 images 表，对所有 file_sha256 为空的行，从对应存储实例读取文件，
 * 计算 SHA-256 回填到 file_sha256；若 file_hash 为空则尽量一并补 MD5。
 * 回填后，后续上传的「MD5 命中 → SHA-256 精确比对」即可零流量直比库值。
 *
 * 用法:
 *   php src/tools/backfill_file_sha256.php [--limit=N] [--stop-on-error]
 *
 * 注意: 对象存储经签名 URL 流式读取（hashFile），涉及一次对象下载；
 *       仅对未回填的存量行执行一次，之后新图上传时服务端已有临时文件。
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

echo "=== 存量图片 SHA-256 回填 ===\n";

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

foreach ($rows as $image) {
    // 幂等：已回填过则跳过
    if (($image->file_sha256 ?? '') !== '') {
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
        // 用该图所属存储实例；多数历史图用默认实例。若图有明确 profile 可进一步精确。
        $profile = StorageProfile::defaultProfile();
        if ($profile === null) {
            $skippedNoStorage++;
            echo "  [{$id}] 无可用存储实例，跳过（driver={$driverName}）\n";
            continue;
        }
        $driver = $profile->driver();

        // 计算 SHA-256（对象存储签名 URL 流式；本地 hash_file）
        $sha = $driver->hashFile($path);
        if ($sha === null) {
            $failed++;
            echo "  [{$id}] hashFile 失败（对象缺失/网络异常，path={$path}）\n";
            if ($stopOnError) exit(1);
            continue;
        }

        // MD5：本地可重算；对象存储用 hashFile 仅得 SHA-256，若 file_hash 为空则留 null，
        // 交由下次上传或二次验证时从存储补算。不强求一致性。
        $md5 = (string) ($image->file_hash ?? '');
        if ($md5 === '' && $driver instanceof LocalDriver) {
            $localMd5 = @hash_file('md5', (string) ($driver->uploadDir() ?? '') . '/' . ltrim($path, '/'));
            if ($localMd5 !== false && $localMd5 !== '') {
                $md5 = $localMd5;
            }
        }

        $image->file_sha256 = $sha;
        if ($md5 !== '') {
            $image->file_hash = $md5;
        }
        if ($image->save()) {
            $updated++;
            echo "  [{$id}] 已回填 SHA-256 (" . substr($sha, 0, 16) . "…), MD5=" . ($md5 !== '' ? substr($md5, 0, 8) . '…' : '(空)') . "\n";
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
