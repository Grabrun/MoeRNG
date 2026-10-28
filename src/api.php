<?php
declare(strict_types=1);

/**
 * MoeRNG - API Entry Point
 * All /api/* requests are routed here via .htaccess
 */

define('MOERNG_START', microtime(true));

require_once __DIR__ . '/bootstrap.php';

$app = \App\Core\Application::create(__DIR__);
$router = $app->router();

// API v1 routes with rate limiting (+ optional API-Key auth gate)
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

// 用 shutdown 钩子而非 run() 之后调用：Controller::json() 结尾是 exit，
// 放在 run() 之后是不可达代码（api_stats.count 会恒为 0，进而让
// Stats::availability() 永远无样本、首页 SLA 徽章退化为硬编码值）。
register_shutdown_function(static function (): void {
    try {
        \App\Core\Stats::bump(\App\Core\Stats::TABLE_API);
    } catch (\Throwable) {
        // 统计永远不该影响 API 响应
    }
});

try {
    $app->run();
} catch (\Throwable $e) {
    // v2.0.0-beta.2: 服务不可用（未捕获异常 → 5xx）单独记账，可用性 = 1 - fail/total。
    // 记录后原样重抛，保持既有 500 行为与错误日志不变。
    \App\Core\Stats::bumpFail(\App\Core\Stats::TABLE_API);
    throw $e;
}
