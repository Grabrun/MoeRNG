<?php
require_once dirname(__DIR__) . '/partials/icons.php';

function admin_header($title) { ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script>var t=localStorage.getItem('moerng-theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= \App\Core\Session::csrfToken() ?>">
    <title><?= h($title) ?> - MoeRNG Admin</title>
    <!-- v1.2.0 迭代: brand favicon on admin pages too -->
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <link rel="stylesheet" href="/public/css/style.css">
    <style>
        .sidebar-brand img { width: 30px; height: 30px; border-radius: 8px; object-fit: contain; }
        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar { width: 240px; background: var(--bg-card); border-right: 1px solid var(--border); padding: 24px 16px; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; z-index: 100; }
        .sidebar-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; padding: 0 12px; gap: 8px; }
        .sidebar-brand { font-size: 1.3rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .sidebar-brand span { line-height: 1; }
        .sidebar-nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: var(--radius-sm); color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; transition: all var(--transition); }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--bg-input); color: var(--text); }
        .sidebar-nav a.active { color: var(--primary); position: relative; }
        /* v1.1.1: active item gets a left accent bar */
        .sidebar-nav a.active::before {
            content: ''; position: absolute; left: -16px; top: 20%; bottom: 20%;
            width: 3px; border-radius: 2px; background: var(--primary);
        }
        .sidebar-nav a span { line-height: 1; }
        .sidebar-footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 4px; }
        .sidebar-footer a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: var(--radius-sm); color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; transition: all var(--transition); }
        .sidebar-footer a:hover { background: var(--bg-input); color: var(--text); }
        .main-content { margin-left: 240px; flex: 1; padding: 32px; min-width: 0; }
        .page-header { margin-bottom: 32px; }
        .page-header h1 { font-size: 1.5rem; }
        .page-header p { color: var(--text-secondary); font-size: 0.9rem; }
        #batch-bar { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--bg-card); border: 1px solid var(--primary); border-radius: var(--radius); padding: 12px 24px; display: flex; align-items: center; gap: 16px; z-index: 500; box-shadow: var(--shadow); }
        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; height: auto; border-right: none; border-bottom: 1px solid var(--border); padding: 16px; }
            .sidebar-nav { flex-direction: row; flex-wrap: wrap; }
            .main-content { margin-left: 0; padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <div class="toast-container"></div>
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="打开菜单" title="打开菜单"><?= icon('menu', 20) ?></button>
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    <div class="admin-layout">
        <aside class="sidebar" id="admin-sidebar">
            <div class="sidebar-top">
                <a href="/admin" class="sidebar-brand"><img src="/assets/logo.png" alt="MoeRNG Logo"><span>MoeRNG</span></a>
                <button type="button" class="theme-toggle" id="theme-toggle" aria-label="切换深浅主题" title="切换深浅主题">
                    <span class="ic icon-sun"><?= icon('sun', 20) ?></span>
                    <span class="ic icon-moon"><?= icon('moon', 20) ?></span>
                </button>
            </div>
            <nav class="sidebar-nav">
                <a href="/admin" class="<?= $title === 'Dashboard' ? 'active' : '' ?>"><?= icon('dashboard', 20) ?><span>Dashboard</span></a>
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
    <script src="/public/js/app.js"></script>
</body>
</html>
<?php }

if (!function_exists('h')) { function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); } }
