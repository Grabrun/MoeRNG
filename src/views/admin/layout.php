<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <title><?= h($title ?? '仪表盘') ?> - MoeRNG Admin</title>
    <!-- v1.2.1-beta.3 迭代: Design Tokens 圆体字族（渐进增强，网络不可达自动回退） -->
    <link rel="stylesheet" href="/public/css/fonts.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="/public/css/style.css?v=<?= APP_VERSION ?>"></head>
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
    <script src="/public/js/helpers.js?v=<?= APP_VERSION ?>"></script>
    <script src="/public/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
