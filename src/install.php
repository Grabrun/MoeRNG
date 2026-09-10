<?php
declare(strict_types=1);

/**
 * MoeRNG - Installation Wizard Entry Point
 * All /install* requests are routed here via .htaccess
 */

define('MOERNG_START', microtime(true));

require_once __DIR__ . '/bootstrap.php';

$app = \App\Core\Application::create(__DIR__);
$router = $app->router();

$controller = \App\Controllers\InstallController::class;

$router->get('/install', [$controller, 'index']);
// v1.3.3-beta.1 修复: 「上一步」是 GET 链接 —— 为 step2/3/4 补 GET 路由（仅重渲染）
$router->get('/install/step2', [$controller, 'showStep2']);
$router->get('/install/step3', [$controller, 'showStep3']);
$router->get('/install/step4', [$controller, 'showStep4']);
$router->post('/install/step2', [$controller, 'step2']);
$router->post('/install/step3', [$controller, 'step3']);
$router->post('/install/step4', [$controller, 'step4']);
$router->post('/install/complete', [$controller, 'complete']);

$app->run();
