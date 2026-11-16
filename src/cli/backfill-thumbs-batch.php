<?php
declare(strict_types=1);

/**
 * 补全缩略图（CLI 单批子进程），由 backfill-thumbs.php 循环调用。
 * 直接构造 Request 调 ImageController::backfillThumbs()（与后台按钮同一份逻辑）。
 */

use App\Core\Request;
use App\Controllers\Admin\ImageController;

$batch = 0;   // 0 = 用 QUEUE_BATCH_DEFAULT，clamp 需在 bootstrap 之后
$scope = 'pending';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batch = (int) substr($arg, 8);
    }
    if ($arg === '--retry') {
        $scope = 'retry';
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// 上限只认 ImageController::QUEUE_BATCH_MAX（单一来源）。
$batch = max(1, min(
    ImageController::QUEUE_BATCH_MAX,
    $batch > 0 ? $batch : ImageController::QUEUE_BATCH_DEFAULT
));

$request = new Request('POST', '/admin/images/backfill-thumbs', [], [
    'scope' => $scope,
    'batch' => (string) $batch,
]);

$controller = new ImageController();
$controller->backfillThumbs($request);

// backfillThumbs 以 json() 结束，此行不可达。
exit(0);
