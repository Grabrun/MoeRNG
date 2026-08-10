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

// API v1 routes with rate limiting
$router->group('/api/v1', function ($router) {
    $controller = \App\Controllers\ApiController::class;

    $router->get('/random', [$controller, 'random']);
    $router->get('/images', [$controller, 'list']);
    $router->get('/categories', [$controller, 'categories']);
    $router->get('/stats', [$controller, 'stats']);
}, [\App\Middleware\RateLimitMiddleware::class]);

$app->run();
