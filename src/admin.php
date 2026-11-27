<?php
declare(strict_types=1);

/**
 * MoeRNG - Admin Panel Entry Point
 */

define('MOERNG_START', microtime(true));

require_once __DIR__ . '/bootstrap.php';

$app = \App\Core\Application::create(__DIR__);
$router = $app->router();

$authController = \App\Controllers\Admin\AuthController::class;
$router->get('/admin/login', [$authController, 'loginForm']);
$router->post('/admin/login', [$authController, 'login'], [\App\Middleware\CsrfMiddleware::class]);
// logout 必须 POST + CSRF，否则 <img> 即可强制登出；刻意不走 CsrfMiddleware，失败时 flash + 302。
$router->post('/admin/logout', [$authController, 'logout']);
$router->get('/admin/captcha', [\App\Core\Captcha::class, 'render']);

$router->group('/admin', function ($router) {
    $dashboard = \App\Controllers\Admin\DashboardController::class;
    $images = \App\Controllers\Admin\ImageController::class;
    $categories = \App\Controllers\Admin\CategoryController::class;
    $settings = \App\Controllers\Admin\SettingController::class;
    $users = \App\Controllers\Admin\UserController::class;
    $apikeys = \App\Controllers\Admin\ApiKeyController::class;

    $router->get('/', [$dashboard, 'index']);

    $router->get('/images', [$images, 'index']);
    $router->get('/images/ids', [$images, 'ids']);
    $router->post('/images/upload', [$images, 'upload']);
    $router->post('/images/precheck', [$images, 'precheck']);
    $router->post('/images/backfill-hashes', [$images, 'backfillHashes']);
    $router->post('/images/backfill-thumbs', [$images, 'backfillThumbs']);
    $router->get('/images/queue', [$images, 'queue']);
    $router->post('/images/process-queue', [$images, 'processQueue']);
    $router->post('/images/requeue-failed', [$images, 'requeueFailed']);
    $router->get('/images/queue-stats', [$images, 'queueStats']);
    $router->post('/images/requeue-one', [$images, 'requeueOne']);
    $router->post('/images/queue-clear', [$images, 'queueClear'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/images/cleanup-storage', [$images, 'cleanupStorage'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/images/convert-originals', [$images, 'convertOriginals'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/images/update', [$images, 'update']);
    $router->post('/images/delete', [$images, 'delete'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/images/batch-delete', [$images, 'batchDelete'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/images/batch-categorize', [$images, 'batchCategorize']);
    $router->post('/images/sort', [$images, 'sort']);

    $router->get('/categories', [$categories, 'index']);
    $router->post('/categories/create', [$categories, 'create']);
    $router->post('/categories/update', [$categories, 'update']);
    $router->post('/categories/delete', [$categories, 'delete']);

    $router->get('/settings', [$settings, 'index']);
    $router->post('/settings/save', [$settings, 'save'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/settings/cache-clear', [$settings, 'cacheClear'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/settings/backup', [$settings, 'backupNow'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/settings/backup-delete', [$settings, 'backupDelete'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->get('/settings/health', [$settings, 'health']);
    $router->post('/settings/health-fix', [$settings, 'healthFix'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/settings/test-mail', [$settings, 'testMail'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/settings/logo-upload', [$settings, 'logoUpload'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->get('/settings/logs', [$settings, 'logs']);
    $router->get('/settings/logs/export', [$settings, 'logsExport']);

    $storageProfiles = \App\Controllers\Admin\StorageProfileController::class;
    $router->get('/storage', [$storageProfiles, 'index'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/storage/profiles/create', [$storageProfiles, 'create'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/storage/profiles/update', [$storageProfiles, 'update'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/storage/profiles/delete', [$storageProfiles, 'delete'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/storage/profiles/toggle', [$storageProfiles, 'toggle'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/storage/profiles/default', [$storageProfiles, 'setDefault'], [\App\Middleware\RequireAdminMiddleware::class]);

    $router->get('/users', [$users, 'index']);
    $router->post('/users/create', [$users, 'create'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/users/update', [$users, 'update'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/users/toggle-status', [$users, 'toggleStatus'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/users/delete', [$users, 'delete'], [\App\Middleware\RequireAdminMiddleware::class]);

    $router->get('/apikeys', [$apikeys, 'index'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/apikeys/create', [$apikeys, 'create'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/apikeys/update', [$apikeys, 'update'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/apikeys/toggle-status', [$apikeys, 'toggleStatus'], [\App\Middleware\RequireAdminMiddleware::class]);
    $router->post('/apikeys/delete', [$apikeys, 'delete'], [\App\Middleware\RequireAdminMiddleware::class]);

}, [\App\Middleware\AuthMiddleware::class, \App\Middleware\CsrfMiddleware::class]);

$app->run();
