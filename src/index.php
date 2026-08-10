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

// Run
$app->run();
