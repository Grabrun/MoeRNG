<?php
declare(strict_types=1);

/**
 * 处理图片队列（CLI）。用法:
 *   php cli/process-queue.php                 # 把积压清空后退出（适合 cron）
 *   php cli/process-queue.php --loop          # 常驻，空闲每 10 秒轮询（适合进程守护）
 *   php cli/process-queue.php --loop=3        # 同上，空闲 3 秒
 *   php cli/process-queue.php --batch=50      # 单批张数（上限见 QUEUE_BATCH_MAX）
 *   php cli/process-queue.php --max-mem=256   # 常驻进程内存上限（MiB），到达后主动退出等守护拉起
 *
 * 单进程内循环：不 fork、不 exec —— 宝塔默认禁用 exec/proc_open/popen/pcntl_fork，
 * 靠子进程的写法在这种环境里起不来。
 */

use App\Controllers\Admin\ImageController;

$batchReq = 0;
$loop = false;
$idle = 10;
$maxMemMb = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batchReq = (int) substr($arg, 8);
    } elseif (str_starts_with($arg, '--max-mem=')) {
        $maxMemMb = max(0, (int) substr($arg, 10));
    } elseif (str_starts_with($arg, '--loop=')) {
        $loop = true;
        $idle = max(1, (int) substr($arg, 7));
    } elseif ($arg === '--loop') {
        $loop = true;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// 上限只认 ImageController::QUEUE_BATCH_MAX（单一来源）。
$batch = max(1, min(
    ImageController::QUEUE_BATCH_MAX,
    $batchReq > 0 ? $batchReq : ImageController::QUEUE_BATCH_DEFAULT
));

$heartbeatFile = ImageController::heartbeatFile();

$beat = static function () use ($heartbeatFile): void {
    $dir = dirname($heartbeatFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($heartbeatFile, (string) time(), LOCK_EX);
};

$say = static function (string $msg): void {
    // stdout 被重定向到文件/管道时 stdio 是块缓冲，不 flush 就要攒够 4KB 才落盘 ——
    // 守护进程里表现为日志永远是 0 字节，排障时等于没有日志。
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
    fflush(STDOUT);
};

/** "512M" / "-1" → 字节数；0 表示不限制。 */
$bytesOf = static function (string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $num = (int) $v;
    return match ($unit) {
        'g' => $num * 1073741824,
        'm' => $num * 1048576,
        'k' => $num * 1024,
        default => $num,
    };
};

// 进程被杀/致命错误时，先把在途行标失败，别让它们卡满 5 分钟复位窗口。
register_shutdown_function(static function (): void {
    $e = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!$e || !in_array($e['type'], $fatal, true)) {
        return;
    }
    $marked = ImageController::markInflightFailed('处理中断（CLI worker 致命错误）: ' . $e['message']);
    fwrite(STDERR, '[FATAL] ' . $e['message'] . ' —— 已标记 ' . $marked . ' 行为 failed' . PHP_EOL);
});

$controller = new ImageController();

$say(sprintf('队列消费者启动：每批 %d 张，%s', $batch, $loop ? "空闲每 {$idle}s 轮询" : '清空后退出'));
$beat();

$round = 0;
$totalDone = 0;
$totalFailed = 0;
$stalled = 0;
$maxStalled = 3;
// 距上次成功往返的时间。MySQL 会按 wait_timeout 掐掉空闲连接，而懒连接不会自愈。
$lastOkAt = time();

while (true) {
    $round++;
    $started = microtime(true);

    // 空闲超过一分钟才探活（忙时每轮本来就动了连接，不必多一次往返）
    if (time() - $lastOkAt >= 60 && !\App\Core\Database::ping() && $round > 1) {
        $say('数据库连接已失效，已重置，下一轮自动重连');
    }

    try {
        $res = $controller->processQueueBatch($batch);
    } catch (\Throwable $e) {
        // 连接级异常（gone away / 2006 / 2013）必须丢弃实例，否则之后每轮都拿到同一个死连接
        \App\Core\Database::reset();
        $say('第 ' . $round . ' 批异常: ' . $e->getMessage());
        if (!$loop) {
            exit(1);
        }
        sleep($idle);
        continue;
    }

    if (empty($res['success'])) {
        $say('第 ' . $round . ' 批返回错误: ' . ($res['error'] ?? 'unknown'));
        if (!$loop) {
            exit(1);
        }
        sleep($idle);
        continue;
    }

    // 到这里说明这一轮数据库往返成功，刷新探活计时
    $lastOkAt = time();

    // 认领冲突不算失败：退避重试，不计入「零进展」。
    if (!empty($res['contended'])) {
        $sleep = 1 + mt_rand(0, 1000) / 1000;
        $say(sprintf('第 %d 批：已被其它消费者认领，%.1fs 后重试（剩余 %d 张）',
            $round, $sleep, (int) ($res['remaining'] ?? 0)));
        usleep((int) ($sleep * 1000000));
        continue;
    }

    $done      = (int) ($res['done'] ?? 0);
    $failed    = (int) ($res['failed'] ?? 0);
    $skipped   = (int) ($res['skipped'] ?? 0);
    $remaining = (int) ($res['remaining'] ?? 0);
    $totalDone += $done;
    $totalFailed += $failed;
    $beat();

    $say(sprintf('第 %d 批：成功 %d 张，失败 %d 张，跳过缩略图 %d 张，剩余 %d 张（%.1fs）',
        $round, $done, $failed, $skipped, $remaining, microtime(true) - $started));

    // done/failed 都为 0 但 remaining 不降，说明认领或复位异常。
    if ($done === 0 && $failed === 0 && $remaining > 0) {
        $stalled++;
        if ($stalled >= $maxStalled) {
            $say("连续 {$maxStalled} 轮零进展，中止。剩余 {$remaining} 张未处理。");
            $say('排查建议：确认 storage/incoming 下有对应的原图，且存储实例可用。');
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
        $beat();
    }

    // 内存看门狗：单进程长跑无法靠换进程回收内存，到阈值就退出交给守护重启。
    $limit = $bytesOf((string) ini_get('memory_limit'));
    $cap = $maxMemMb > 0 ? $maxMemMb * 1048576 : ($limit > 0 ? (int) ($limit * 0.75) : 0);
    if ($cap > 0 && memory_get_usage(true) >= $cap) {
        $say(sprintf('内存 %d MiB 达到上限 %d MiB，主动退出（守护进程会重新拉起）',
            (int) round(memory_get_usage(true) / 1048576), (int) round($cap / 1048576)));
        exit(0);
    }
    gc_collect_cycles();
}

$say("处理完成，共成功 {$totalDone} 张" . ($totalFailed > 0 ? "，失败 {$totalFailed} 张（可在后台「重试失败项」重跑）" : ''));
