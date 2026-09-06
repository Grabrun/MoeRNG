<?php
declare(strict_types=1);

/**
 * MoeRNG - Random Anime Image API Service
 * Entry Point: Frontend Home Page
 */

define('MOERNG_START', microtime(true));

// Autoloading + global helpers (see bootstrap.php)
require_once __DIR__ . '/bootstrap.php';

// Bootstrap
$app = \App\Core\Application::create(__DIR__);

// Define routes
$router = $app->router();

$router->get('/', [\App\Controllers\HomeController::class, 'index']);
// v1.3.1 迭代: 前台多页导航 —— 单页内容拆分为独立路由页面。
$router->get('/docs', [\App\Controllers\HomeController::class, 'docs']);
$router->get('/tester', [\App\Controllers\HomeController::class, 'tester']);
$router->get('/about', [\App\Controllers\HomeController::class, 'about']);
// v1.3.1 迭代: 前台图库页（公开分页浏览，20 张/页）。
$router->get('/gallery', [\App\Controllers\HomeController::class, 'gallery']);
// v1.2.0 迭代: signed download endpoint for local storage (short-lived links).
$router->get('/files', [\App\Controllers\FileController::class, 'show']);

// Run
$app->run();
