<?php
declare(strict_types=1);

/**
 * MoeRNG - Random Anime Image API Service
 */

define('MOERNG_START', microtime(true));

require_once __DIR__ . '/bootstrap.php';

$app = \App\Core\Application::create(__DIR__);

$router = $app->router();

$router->get('/', [\App\Controllers\HomeController::class, 'index']);
$router->get('/docs', [\App\Controllers\HomeController::class, 'docs']);
$router->get('/tester', [\App\Controllers\HomeController::class, 'tester']);
$router->get('/about', [\App\Controllers\HomeController::class, 'about']);
$router->get('/gallery', [\App\Controllers\HomeController::class, 'gallery']);
$router->get('/gallery/feed', [\App\Controllers\HomeController::class, 'galleryFeed']);
$router->get('/files', [\App\Controllers\FileController::class, 'show']);

$app->run();
