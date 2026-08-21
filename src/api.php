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

$app->run();

// v1.2.0 迭代: record the API call (after run so 404s don't count; best effort).
\App\Core\Stats::bump(\App\Core\Stats::TABLE_API);
