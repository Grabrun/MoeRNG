<?php
declare(strict_types=1);

/**
 * 处理队列（CLI 单批子进程），由 process-queue.php 循环调用。
 * 直接构造 Request 调 ImageController::processQueue()（与后台按钮同一份逻辑），返回 JSON 后退出。
 */

use App\Core\Request;
use App\Controllers\Admin\ImageController;

$batch = 0;   // 0 = 用 QUEUE_BATCH_DEFAULT，clamp 需在 bootstrap 之后
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

// processQueue 以 json() 结束，此行不可达。
exit(0);
