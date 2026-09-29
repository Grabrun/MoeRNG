<?php
declare(strict_types=1);

/**
 * MoeRNG - 一键补全缩略图（CLI 父脚本）
 *
 * 用法（服务器部署目录下执行）：
 *   php src/cli/backfill-thumbs.php [--batch 10] [--retry]
 *
 * 作用：把「process_status='done' 但 thumb_meta 为 pending（无缩略图）」的
 * 历史图片批量补齐缩略图（sm 320 / md 640 / lg 1280 WebP），补齐后前台
 * /gallery 图库自动改用缩略图（不再回退原图）。
 *
 * 实现：每批调用一次子进程（src/cli/backfill-thumbs-batch.php），子进程
 * 构造 Request 走 ImageController::backfillThumbs()（同一份生成/上传/落库
 * 逻辑，零分叉），父脚本按返回的 remaining 循环直到队列清空。
 * 选择子进程而非同进程多轮：backfillThumbs 内部以 json() 结束（exit），
 * 单进程无法循环调用。
 *
 * 可选 --retry：额外对 thumb_meta 为 partial/failed 的行做定向重试。
 */

use App\Core\Database;

$batch = 10;
$withRetry = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--retry') {
        $withRetry = true;
    } elseif (str_starts_with($arg, '--batch=')) {
        $batch = max(1, min(10, (int) substr($arg, 8)));
    }
}

require dirname(__DIR__) . '/bootstrap.php';

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

    // 硬失败（原图取不到/存储不可达）不写状态、remaining 不推进 —— 多批无进展则中止
    if ($doneThis === 0 && $failedThis > 0 && $remaining > 0) {
        echo "连续失败（本批 0 成功）：可能存在硬失败（原图缺失/存储不可达）。\n";
        echo "请先检查存储实例与 incoming 目录，再重新执行。剩余 {$remaining} 张未处理。\n";
        exit(1);
    }

    // 防死循环：多批无进展则中止
    if ($round > 500) {
        echo "达到批次上限（500），中止。剩余 {$remaining} 张。\n";
        break;
    }
}

echo "补全完成，共处理 {$processed} 张。\n";

if ($withRetry) {
    echo "（--retry 模式：请再执行 php src/cli/backfill-thumbs.php --retry 以重试 partial/failed 行）\n";
}
