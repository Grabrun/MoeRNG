<?php
declare(strict_types=1);

/**
 * MoeRNG - API Entry Point
 */

define('MOERNG_START', microtime(true));

require_once __DIR__ . '/bootstrap.php';

$app = \App\Core\Application::create(__DIR__);
$router = $app->router();

$router->group('/api/v1', function ($router) {
    $controller = \App\Controllers\ApiController::class;

    $router->get('/random', [$controller, 'random']);
    $router->get('/images', [$controller, 'list']);
    $router->get('/categories', [$controller, 'categories']);
    $router->get('/stats', [$controller, 'stats']);
}, [
    \App\Middleware\ApiKeyAuthMiddleware::class,
    \App\Middleware\RateLimitMiddleware::class,
]);

// 用 shutdown 钩子记账：Controller::json() 以 exit 结束，放在 run() 之后不可达。
register_shutdown_function(static function (): void {
    try {
        \App\Core\Stats::bump(\App\Core\Stats::TABLE_API);
    } catch (\Throwable) {
        // 统计不应影响 API 响应。
    }
});

try {
    $app->run();
} catch (\Throwable $e) {
    // 未捕获异常单独记账后原样重抛。
    \App\Core\Stats::bumpFail(\App\Core\Stats::TABLE_API);
    throw $e;
}
