<?php
declare(strict_types=1);

/**
 * MoeRNG - 处理队列（CLI 单批子进程）
 *
 * 由 process-queue.php 循环调用，每批处理 batch 张。
 * 构造带合法 CSRF 的 Request，走 ImageController::processQueue()
 * （与后台「开始处理」按钮同一份逻辑，零分叉），返回 JSON 后自然退出。
 *
 * 与 backfill-thumbs-batch.php 同构：processQueue() 内部以 json() 结束（exit），
 * 无法在同进程内循环，故拆成「父循环 + 单批子进程」。
 */

use App\Core\Request;
use App\Core\Session;
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

// CLI 无 Web 会话：自行启动 session 并生成合法 CSRF token，
// 使 processQueue() 开头的 validateCsrf() 通过（校验的是 session 内 token）。
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_POST['_csrf_token'] = Session::csrfToken();

$request = new Request('POST', '/admin/images/process-queue', [], [
    'batch' => (string) $batch,
], [], '');

$controller = new ImageController();
$controller->processQueue($request);

// processQueue 以 json() 结束（exit），此处不可达。
exit(0);
