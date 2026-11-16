<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <?php /* 队列单批张数与 ImageController::QUEUE_BATCH_MAX 同一来源。 */ ?>
    <meta name="queue-batch" content="<?= \App\Controllers\Admin\ImageController::QUEUE_BATCH_MAX ?>">
    <title><?= h($title ?? '仪表盘') ?> - MoeRNG Admin</title>
    <link rel="preconnect" href="https://miaoda.feishu.cn" crossorigin>
    <link rel="preconnect" href="https://sf3-scmcdn-cn.feishucdn.com" crossorigin>
    <link rel="dns-prefetch" href="https://miaoda.feishu.cn">
    <link rel="dns-prefetch" href="https://sf3-scmcdn-cn.feishucdn.com">
    <!-- 字体 CSS 异步化：media=print 不阻塞首屏，nonce 脚本立即切回 all（CSP 禁 inline onload）。 -->
    <link rel="stylesheet" href="https://miaoda.feishu.cn/fonts/css2?family=Noto+Serif+SC:wght@600;700&family=Noto+Sans+SC:wght@400;500;700&display=swap" id="font-css" media="print">
    <script<?= \App\Core\CspNonce::attr() ?>>
    try{var l=document.getElementById('font-css');if(l){l.media='all';}}catch(e){}
    </script>
    <link rel="stylesheet" href="/public/css/style.css?v=<?= ASSET_VER ?>"></head>
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
                <a href="/" target="_blank" class="mt-3 border-top pt-3">返回前台</a>
                <!-- 退出登录必须 POST + CSRF：GET 链接可被 <img src> 强制触发。 -->
                <form method="POST" action="/admin/logout" class="logout-form">
                    <input type="hidden" name="_csrf_token" value="<?= \App\Core\Session::csrfToken() ?>">
                    <button type="submit">退出登录</button>
                </form>
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
    <script src="/public/js/helpers.js?v=<?= ASSET_VER ?>"></script>
    <script src="/public/js/app.js?v=<?= ASSET_VER ?>"></script>
</body>
</html>
