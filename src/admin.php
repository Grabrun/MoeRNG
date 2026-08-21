<?php
declare(strict_types=1);

/**
 * MoeRNG - Admin Panel Entry Point
 * All /admin* requests are routed here via .htaccess
 */

define('MOERNG_START', microtime(true));

require_once __DIR__ . '/bootstrap.php';

$app = \App\Core\Application::create(__DIR__);
$router = $app->router();

// Public admin routes (no auth required)
$authController = \App\Controllers\Admin\AuthController::class;
$router->get('/admin/login', [$authController, 'loginForm']);
$router->post('/admin/login', [$authController, 'login']);
$router->get('/admin/logout', [$authController, 'logout']);
// v1.1.0-beta.4: captcha image (needed by the login form before auth).
$router->get('/admin/captcha', [\App\Core\Captcha::class, 'render']);

// Protected admin routes
$router->group('/admin', function ($router) {
    $dashboard = \App\Controllers\Admin\DashboardController::class;
    $images = \App\Controllers\Admin\ImageController::class;
    $categories = \App\Controllers\Admin\CategoryController::class;
    $settings = \App\Controllers\Admin\SettingController::class;
    $users = \App\Controllers\Admin\UserController::class;
    $apikeys = \App\Controllers\Admin\ApiKeyController::class;

    // 仪表盘
    $router->get('/', [$dashboard, 'index']);

    // Images
    $router->get('/images', [$images, 'index']);
    $router->get('/images/ids', [$images, 'ids']);
    $router->post('/images/upload', [$images, 'upload']);
    $router->post('/images/update', [$images, 'update']);
    $router->post('/images/delete', [$images, 'delete']);
    $router->post('/images/batch-delete', [$images, 'batchDelete']);
    $router->post('/images/sort', [$images, 'sort']);

    // Categories
    $router->get('/categories', [$categories, 'index']);
    $router->post('/categories/create', [$categories, 'create']);
    $router->post('/categories/update', [$categories, 'update']);
    $router->post('/categories/delete', [$categories, 'delete']);

    // Settings (site + security + performance + mail + backup; storage on its own board)
    $router->get('/settings', [$settings, 'index']);
    $router->post('/settings/save', [$settings, 'save']);
    $router->post('/settings/cache-clear', [$settings, 'cacheClear']);
    $router->post('/settings/backup', [$settings, 'backupNow']);
    $router->post('/settings/backup-delete', [$settings, 'backupDelete']);
    $router->post('/settings/test-mail', [$settings, 'testMail']);
    $router->post('/settings/logo-upload', [$settings, 'logoUpload']);
    $router->get('/settings/logs', [$settings, 'logs']);
    // v1.2.1 迭代: CSV export of the filtered audit trail.
    $router->get('/settings/logs/export', [$settings, 'logsExport']);

    // Storage profiles — top-level board, peer of 图片管理 / 系统设置
    $storageProfiles = \App\Controllers\Admin\StorageProfileController::class;
    $router->get('/storage', [$storageProfiles, 'index']);
    $router->post('/storage/profiles/create', [$storageProfiles, 'create']);
    $router->post('/storage/profiles/update', [$storageProfiles, 'update']);
    $router->post('/storage/profiles/delete', [$storageProfiles, 'delete']);
    $router->post('/storage/profiles/toggle', [$storageProfiles, 'toggle']);
    $router->post('/storage/profiles/default', [$storageProfiles, 'setDefault']);

    // Users
    $router->get('/users', [$users, 'index']);
    $router->post('/users/create', [$users, 'create']);
    $router->post('/users/update', [$users, 'update']);
    $router->post('/users/toggle-status', [$users, 'toggleStatus']);
    $router->post('/users/delete', [$users, 'delete']);

    // API Keys
    $router->get('/apikeys', [$apikeys, 'index']);
    $router->post('/apikeys/create', [$apikeys, 'create']);
    $router->post('/apikeys/update', [$apikeys, 'update']);
    $router->post('/apikeys/toggle-status', [$apikeys, 'toggleStatus']);
    $router->post('/apikeys/delete', [$apikeys, 'delete']);

}, [\App\Middleware\AuthMiddleware::class, \App\Middleware\CsrfMiddleware::class]);

$app->run();
