<?php
require_once dirname(__DIR__) . '/partials/icons.php';

function admin_header($title) { ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= \App\Core\Session::csrfToken() ?>">
    <title><?= h($title) ?> - MoeRNG Admin</title>
    <!-- v1.2.0 迭代: brand favicon on admin pages too -->
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <link rel="stylesheet" href="/public/css/style.css?v=<?= APP_VERSION ?>">
    
</head>
<body>
    <div class="toast-container"></div>
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="打开菜单" title="打开菜单"><?= icon('menu', 20) ?></button>
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    <div class="admin-layout">
        <aside class="sidebar" id="admin-sidebar">
            <div class="sidebar-top">
                <a href="/admin" class="sidebar-brand"><img src="/assets/favicon-32.png" width="30" height="30" alt="MoeRNG Logo"><span>MoeRNG</span></a>
                <button type="button" class="theme-toggle" id="theme-toggle" aria-label="切换深浅主题" title="切换深浅主题">
                    <span class="ic icon-sun"><?= icon('sun', 20) ?></span>
                    <span class="ic icon-moon"><?= icon('moon', 20) ?></span>
                </button>
            </div>
            <nav class="sidebar-nav">
                <a href="/admin" class="<?= $title === '仪表盘' ? 'active' : '' ?>"><?= icon('dashboard', 20) ?><span>仪表盘</span></a>
                <a href="/admin/images" class="<?= $title === '图片管理' ? 'active' : '' ?>"><?= icon('image', 20) ?><span>图片管理</span></a>
                <a href="/admin/categories" class="<?= $title === '分类管理' ? 'active' : '' ?>"><?= icon('folder-tree', 20) ?><span>分类管理</span></a>
                <a href="/admin/storage" class="<?= $title === '存储管理' ? 'active' : '' ?>"><?= icon('cloud', 20) ?><span>存储管理</span></a>
                <a href="/admin/settings" class="<?= $title === '系统设置' ? 'active' : '' ?>"><?= icon('settings', 20) ?><span>系统设置</span></a>
                <a href="/admin/settings/logs" class="<?= $title === '操作日志' ? 'active' : '' ?>"><?= icon('clipboard', 20) ?><span>操作日志</span></a>
                <a href="/admin/users" class="<?= $title === '用户管理' ? 'active' : '' ?>"><?= icon('users', 20) ?><span>用户管理</span></a>
                <a href="/admin/apikeys" class="<?= $title === 'API Key 管理' ? 'active' : '' ?>"><?= icon('key', 20) ?><span>API Keys</span></a>
            </nav>
            <div class="sidebar-footer">
                <a href="/" target="_blank"><?= icon('link', 20) ?><span>返回前台</span></a>
                <a href="/admin/logout"><?= icon('logout', 20) ?><span>退出登录</span></a>
            </div>
        </aside>
        <main class="main-content">
<?php admin_flash(); }

/**
 * Render one-shot flash messages.
 *
 * Every controller writes Session::flash('success'|'error', ...) before
 * redirecting, but this header never printed them, so all admin actions
 * appeared to do nothing. `views/admin/layout.php` had the markup and is dead
 * code — the real pages all go through admin_header().
 */
function admin_flash() {
    $flashes = [
        'success' => \App\Core\Session::flash('success'),
        'error'   => \App\Core\Session::flash('error'),
        'warning' => \App\Core\Session::flash('warning'),
        'info'    => \App\Core\Session::flash('info'),
    ];

    foreach ($flashes as $type => $message) {
        if ($message === null || $message === '') continue;
        // Controllers join upload errors with "<br>", so limited markup is
        // allowed while everything else stays escaped.
        $safe = strip_tags((string) $message, '<br><b><strong>');
        echo '<div class="alert alert-' . $type . '" data-flash="' . $type . '">' . $safe . '</div>';
    }
}

function admin_footer() { ?>
        </main>
    </div>
    <script src="/public/js/helpers.js?v=<?= APP_VERSION ?>"></script>
    <script src="/public/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
<?php }

if (!function_exists('h')) { function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); } }
