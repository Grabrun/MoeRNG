<?php
declare(strict_types=1);

/**
 * MoeRNG - 补全缩略图（CLI 单批子进程）
 *
 * 由 backfill-thumbs.php 循环调用，每批处理 batch 张。
 * 构造带合法 CSRF 的 Request，走 ImageController::backfillThumbs()
 * （与后台「补全缩略图」按钮同一份逻辑），返回 JSON 后自然退出。
 */

use App\Core\Request;
use App\Core\Session;
use App\Controllers\Admin\ImageController;

$batch = 3;
$scope = 'pending';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batch = max(1, min(10, (int) substr($arg, 8)));
    }
    if ($arg === '--retry') {
        $scope = 'retry';
    }
}

require dirname(__DIR__) . '/bootstrap.php';

// CLI 无 Web 会话：自行启动 session 并生成合法 CSRF token，
// 使 backfillThumbs() 开头的 validateCsrf() 通过（校验的是 session 内 token）。
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_POST['_csrf_token'] = Session::csrfToken();

$request = new Request('POST', '/admin/images/backfill-thumbs', [], [
    'scope' => $scope,
    'batch' => (string) $batch,
], [], '');

$controller = new ImageController();
$controller->backfillThumbs($request);

// backfillThumbs 以 json() 结束（exit），此处不可达。
exit(0);
