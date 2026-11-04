<?php
declare(strict_types=1);

/**
 * MoeRNG - 处理图片队列（CLI 父脚本）
 *
 * 用法（服务器部署目录下执行）：
 *   php src/cli/process-queue.php                  # 清空积压后退出（适合 cron）
 *   php src/cli/process-queue.php --loop           # 常驻，空闲每 10 秒轮询（适合 supervisor）
 *   php src/cli/process-queue.php --batch=50       # 指定单批张数（上限见 QUEUE_BATCH_MAX）
 *   php src/cli/process-queue.php --loop=30        # 常驻，空闲 30 秒轮询
 *
 * 背景：队列原先**只有浏览器这一个消费者**（上传完成后自动跑一次、或管理员点
 * 「开始处理」）。关掉标签页就彻底停摆，无人操作则积压永不消化。
 * 本脚本补上服务端消费者；两个入口靠既有的「条件 UPDATE 认领」天然互斥
 * （认领失败返回 contended，本脚本退避后重试），无需额外协调。
 *
 * 实现与 backfill-thumbs.php 同构：每批起一个子进程
 * （process-queue-batch.php）走 ImageController::processQueue()，父脚本按
 * 返回的 remaining 循环。子进程的必要性见该文件注释。
 */

use App\Core\Database;

$batch = 0;      // 0 = 用 ImageController::QUEUE_BATCH_DEFAULT
$loop = false;   // false = 清空后退出
$idle = 10;      // --loop 模式下的空闲轮询间隔（秒）
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
$stalled = 0;      // 连续无进展的轮数（防死循环）
$maxStalled = 3;   // 连续 3 轮零进展即判定卡死

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

    // 认领冲突：另一个消费者（浏览器标签页 / 另一个 worker）抢先拿了这批。
    // 不是失败 —— 退避一下重试，绝不当成「零进展」把 worker 判死。
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

    // 零进展判定：done=0 且 failed=0 但 remaining 不降 → 认领或复位出了问题，
    // 继续转只会空转。failed>0 的情况由下一轮自然重试（失败行不留在 pending）。
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
        // 常驻模式：清空后转空闲轮询
        usleep($idle * 1000000);
        $stalled = 0;
        continue;
    }

    // 防死循环（--loop 模式下不设上限，由 stalled 兜底）
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
