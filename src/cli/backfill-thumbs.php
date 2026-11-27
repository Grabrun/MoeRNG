<?php
declare(strict_types=1);

/**
 * 一键补全缩略图（CLI 父脚本），逐批起子进程调 ImageController::backfillThumbs()。
 * 用法: php src/cli/backfill-thumbs.php [--batch=张数] [--retry]；--retry 额外重试 partial/failed 行
 */

use App\Core\Database;

$batch = 0;   // 0 = 用 QUEUE_BATCH_DEFAULT，clamp 需在 bootstrap 之后
$withRetry = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--retry') {
        $withRetry = true;
    } elseif (str_starts_with($arg, '--batch=')) {
        $batch = (int) substr($arg, 8);
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// 本脚本靠 exec 起子进程；宝塔默认禁用 exec（PHP 8 下直接 Error），先给出可执行的替代方案。
if (!function_exists('exec')) {
    fwrite(STDERR, "exec 已被 disable_functions 禁用，本脚本无法分批执行。\n"
        . "替代方案：后台「图片处理」页的「补全缩略图」按钮（同一份实现），或临时放开 exec 后再运行。\n");
    exit(1);
}

// 上限只认 QUEUE_BATCH_MAX（单一来源）。
$batch = max(1, min(
    \App\Controllers\Admin\ImageController::QUEUE_BATCH_MAX,
    $batch > 0 ? $batch : \App\Controllers\Admin\ImageController::QUEUE_BATCH_DEFAULT
));

try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

$noThumbSql = "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND "
    . \App\Models\Image::thumbMetaPendingSql();

$total = (int) $pdo->query($noThumbSql)->fetchColumn();
if ($total === 0) {
    echo "没有需要补全的图片（所有已完成图片都有缩略图）。\n";
    if (!$withRetry) {
        exit(0);
    }
}

$batchScript = escapeshellarg(__DIR__ . '/backfill-thumbs-batch.php');
$phpBin = PHP_BINARY;

$round = 0;
$processed = 0;
$remaining = $total;

echo sprintf("待补全 %d 张（每批 %d 张）...\n", $total, $batch);
while ($remaining > 0) {
    $round++;
    $cmd = escapeshellarg($phpBin) . ' ' . $batchScript . ' --batch=' . $batch;
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $line = implode("\n", $out);

    $j = null;
    // 取最后一行 JSON（shutdown 钩子可能混入其它输出）
    foreach (array_reverse($out) as $l) {
        $decoded = json_decode(trim($l), true);
        if (is_array($decoded)) {
            $j = $decoded;
            break;
        }
    }

    if ($code !== 0 || $j === null) {
        echo "第 {$round} 批执行失败（exit={$code}）:\n{$line}\n";
        exit(1);
    }
    if (empty($j['success'])) {
        echo "第 {$round} 批返回错误: " . ($j['error'] ?? 'unknown') . "\n";
        exit(1);
    }

    $doneThis = (int) ($j['done'] ?? 0);
    $failedThis = (int) ($j['failed'] ?? 0);
    $processed += $doneThis;
    $remaining = (int) ($j['remaining'] ?? 0);
    echo sprintf("第 %d 批：成功 %d 张，失败 %d 张，剩余 %d 张\n",
        $round, $doneThis, $failedThis, $remaining);

    // 硬失败不写状态、remaining 不推进，多批无进展则中止。
    if ($doneThis === 0 && $failedThis > 0 && $remaining > 0) {
        echo "连续失败（本批 0 成功）：可能存在硬失败（原图缺失/存储不可达）。\n";
        echo "请先检查存储实例与 incoming 目录，再重新执行。剩余 {$remaining} 张未处理。\n";
        exit(1);
    }

    if ($round > 500) {
        echo "达到批次上限（500），中止。剩余 {$remaining} 张。\n";
        break;
    }
}

echo "补全完成，共处理 {$processed} 张。\n";

if ($withRetry) {
    echo "（--retry 模式：请再执行 php src/cli/backfill-thumbs.php --retry 以重试 partial/failed 行）\n";
}
