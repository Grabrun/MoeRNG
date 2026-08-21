<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <title><?= h($title ?? '仪表盘') ?> - MoeRNG Admin</title>
    <!-- v1.2.1-beta.3 迭代: Design Tokens 圆体字族（渐进增强，网络不可达自动回退） -->
    <link rel="stylesheet" href="/public/css/fonts.css">
    <link rel="stylesheet" href="/public/css/style.css">
    <style>
        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 240px; background: var(--bg-card); border-right: 1px solid var(--border);
            padding: 24px 16px; position: fixed; top: 0; left: 0; bottom: 0;
            overflow-y: auto; z-index: 100;
        }
        .sidebar-brand {
            font-size: 1.3rem; font-weight: 700; color: var(--primary);
            margin-bottom: 32px; display: block; text-decoration: none;
            padding: 0 12px;
        }
        .sidebar-nav { display: flex; flex-direction: column; gap: 4px; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: var(--radius-sm);
            color: var(--text-secondary); text-decoration: none;
            font-size: 0.9rem; transition: all var(--transition);
        }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--bg-input); color: var(--text); }
        .sidebar-nav a.active { color: var(--primary); }
        .main-content { margin-left: 240px; flex: 1; padding: 32px; min-width: 0; }
        .page-header { margin-bottom: 32px; }
        .page-header h1 { font-size: 1.5rem; }
        .page-header p { color: var(--text-secondary); font-size: 0.9rem; }

        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; height: auto; border-right: none; border-bottom: 1px solid var(--border); padding: 16px; }
            .sidebar-nav { flex-direction: row; flex-wrap: wrap; }
            .main-content { margin-left: 0; padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <div class="toast-container"></div>
    <div class="admin-layout">
        <aside class="sidebar">
            <a href="/admin" class="sidebar-brand">MoeRNG</a>
            <nav class="sidebar-nav">
                <a href="/admin" class="<?= ($title ?? '') === '仪表盘' ? 'active' : '' ?>">仪表盘</a>
                <a href="/admin/images" class="<?= ($title ?? '') === '图片管理' ? 'active' : '' ?>">图片管理</a>
                <a href="/admin/categories" class="<?= ($title ?? '') === '分类管理' ? 'active' : '' ?>">分类管理</a>
                <a href="/admin/settings" class="<?= ($title ?? '') === '系统设置' ? 'active' : '' ?>">系统设置</a>
                <a href="/admin/users" class="<?= ($title ?? '') === '用户管理' ? 'active' : '' ?>">用户管理</a>
                <a href="/admin/apikeys" class="<?= ($title ?? '') === 'API Key 管理' ? 'active' : '' ?>">API Keys</a>
                <a href="/" target="_blank" style="margin-top:24px;border-top:1px solid var(--border);padding-top:16px">返回前台</a>
                <a href="/admin/logout">退出登录</a>
            </nav>
        </aside>
        <main class="main-content">
            <?php if ($flash = \App\Core\Session::flash('success')): ?>
            <div class="alert alert-success"><?= $flash ?></div>
            <?php endif; ?>
            <?php if ($flash = \App\Core\Session::flash('error')): ?>
            <div class="alert alert-error"><?= $flash ?></div>
            <?php endif; ?>
            <?= $content ?? '' ?>
        </main>
    </div>
    <script src="/public/js/app.js"></script>
</body>
</html>
