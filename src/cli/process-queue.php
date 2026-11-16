<?php
declare(strict_types=1);

/**
 * 处理图片队列（CLI 父脚本），每批起一个子进程。
 * 用法: php src/cli/process-queue.php [--loop[=秒]] [--batch=张数]；默认清空后退出
 */

use App\Core\Database;

$batch = 0;      // 0 = 用 QUEUE_BATCH_DEFAULT
$loop = false;
$idle = 10;      // --loop 空闲轮询秒数
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batch = (int) substr($arg, 8);
    } elseif ($arg === '--loop') {
        $loop = true;
    } elseif (str_starts_with($arg, '--loop=')) {
        $loop = true;
        $idle = max(1, (int) substr($arg, 7));
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// 上限只认 ImageController::QUEUE_BATCH_MAX（单一来源）。
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

$pendingCount = static function () use ($pdo): int {
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM `images` WHERE process_status = 'pending'"
    )->fetchColumn();
};

$batchScript = escapeshellarg(__DIR__ . '/process-queue-batch.php');
$phpBin = PHP_BINARY;

$round = 0;
$processed = 0;
$failedTotal = 0;
$stalled = 0;
$maxStalled = 3;

$pending = $pendingCount();
if ($pending === 0) {
    echo "队列为空，无需处理。\n";
    if (!$loop) {
        exit(0);
    }
}

echo sprintf("待处理 %d 张（每批 %d 张%s）...\n",
    $pending, $batch, $loop ? "，空闲每 {$idle}s 轮询" : '');

while (true) {
    $round++;
    $cmd = escapeshellarg($phpBin) . ' ' . $batchScript . ' --batch=' . $batch;
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

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
        echo "第 {$round} 批执行失败（exit={$code}）:\n" . implode("\n", $out) . "\n";
        exit(1);
    }
    if (empty($j['success'])) {
        echo "第 {$round} 批返回错误: " . ($j['error'] ?? 'unknown') . "\n";
        exit(1);
    }

    // 认领冲突不算失败：退避重试，不计入「零进展」。
    if (!empty($j['contended'])) {
        $sleep = 1 + mt_rand(0, 1000) / 1000;
        echo sprintf("第 %d 批：已被其它消费者认领，%.1fs 后重试（剩余 %d 张）\n",
            $round, $sleep, (int) ($j['remaining'] ?? 0));
        usleep((int) ($sleep * 1000000));
        continue;
    }

    $doneThis = (int) ($j['done'] ?? 0);
    $failedThis = (int) ($j['failed'] ?? 0);
    $skippedThis = (int) ($j['skipped'] ?? 0);
    $remaining = (int) ($j['remaining'] ?? 0);
    $processed += $doneThis;
    $failedTotal += $failedThis;

    echo sprintf("第 %d 批：成功 %d 张，失败 %d 张，跳过缩略图 %d 张，剩余 %d 张\n",
        $round, $doneThis, $failedThis, $skippedThis, $remaining);

    // done/failed 都为 0 但 remaining 不降，说明认领或复位异常。
    if ($doneThis === 0 && $failedThis === 0 && $remaining > 0) {
        $stalled++;
        if ($stalled >= $maxStalled) {
            echo "连续 {$maxStalled} 轮零进展，中止。剩余 {$remaining} 张未处理。\n";
            echo "排查建议：确认 storage/incoming 下有对应的原图，且存储实例可用。\n";
            exit(1);
        }
    } else {
        $stalled = 0;
    }

    if ($remaining === 0) {
        if (!$loop) {
            break;
        }
        usleep($idle * 1000000);
        $stalled = 0;
        continue;
    }

    if (!$loop && $round > 100000) {
        echo "达到批次上限，中止。剩余 {$remaining} 张。\n";
        break;
    }
}

echo "处理完成，共成功 {$processed} 张";
if ($failedTotal > 0) {
    echo "，失败 {$failedTotal} 张（可在后台「重试失败项」重跑）";
}
echo "。\n";
