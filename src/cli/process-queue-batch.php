<?php
declare(strict_types=1);

/**
 * MoeRNG - 处理队列（CLI 单批子进程）
 *
 * 由 process-queue.php 循环调用，每批处理 batch 张。
 * 直接构造 Request 调 ImageController::processQueue()（与后台「开始处理」同一份逻辑，
 * 零分叉），返回 JSON 后自然退出。
 *
 * 不经 Router → 不经过 CsrfMiddleware，因此**不需要**伪造 CSRF token / 启动 session。
 * （v2.0.0-beta.19：此前这里用 `$_POST['_csrf_token'] = …` + 自行 session_start 去满足
 *  控制器里的 validateCsrf()，而那个校验本身已删除；顺带修掉一处实参错位 ——
 *  原调用把 `''` 传给了 `array $headers`，strict_types 下会直接 TypeError。）
 *
 * 与 backfill-thumbs-batch.php 同构：processQueue() 内部以 json() 结束（exit），
 * 无法在同进程内循环，故拆成「父循环 + 单批子进程」。
 */

use App\Core\Request;
use App\Controllers\Admin\ImageController;

$batch = 0;   // 0 = 用 ImageController::QUEUE_BATCH_DEFAULT；clamp 放在 bootstrap 之后（常量要能看到类）
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batch = (int) substr($arg, 8);
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// 上限只认 ImageController::QUEUE_BATCH_MAX（单一来源）。
$batch = max(1, min(
    ImageController::QUEUE_BATCH_MAX,
    $batch > 0 ? $batch : ImageController::QUEUE_BATCH_DEFAULT
));

$request = new Request('POST', '/admin/images/process-queue', [], [
    'batch' => (string) $batch,
]);

$controller = new ImageController();
$controller->processQueue($request);

// processQueue 以 json() 结束（exit），此处不可达。
exit(0);
