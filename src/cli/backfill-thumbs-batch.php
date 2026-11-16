<?php
declare(strict_types=1);

/**
 * MoeRNG - 补全缩略图（CLI 单批子进程）
 *
 * 由 backfill-thumbs.php 循环调用，每批处理 batch 张。
 * 直接构造 Request 调 ImageController::backfillThumbs()（与后台「补全缩略图」按钮
 * 同一份逻辑），返回 JSON 后自然退出。
 *
 * 不经 Router → 不经过 CsrfMiddleware，因此不需要伪造 CSRF token / 启动 session。
 * （v2.0.0-beta.19：同时修掉 `''` 被传给 `array $headers` 的实参错位 ——
 *  strict_types 下会直接 TypeError。）
 */

use App\Core\Request;
use App\Controllers\Admin\ImageController;

$batch = 0;   // 0 = 用 ImageController::QUEUE_BATCH_DEFAULT；clamp 放在 bootstrap 之后（常量要能看到类）
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

// backfillThumbs 以 json() 结束（exit），此处不可达。
exit(0);
