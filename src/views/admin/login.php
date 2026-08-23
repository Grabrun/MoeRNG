<?php require_once __DIR__ . '/../partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="csrf-token" content="<?= $csrf_token ?>">
    <title>Login - MoeRNG Admin</title>
    <!-- v1.2.0 迭代: brand favicon on the login page too -->
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <!-- v1.2.1-beta.3 迭代: Design Tokens 圆体字族（渐进增强） -->
    <link rel="stylesheet" href="/public/css/fonts.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="/public/css/style.css?v=<?= APP_VERSION ?>">
    
</head>
<body>
    <button type="button" class="theme-toggle theme-toggle-float" id="theme-toggle" aria-label="切换深浅主题" title="切换深浅主题">
        <span class="ic icon-sun"><?= icon('sun', 20) ?></span>
        <span class="ic icon-moon"><?= icon('moon', 20) ?></span>
    </button>
    <div class="login-page">
        <div class="login-card">
            <img class="login-logo" src="/assets/logo-72.png" width="72" height="72" alt="MoeRNG Logo">
            <h1 class="sr-only">MoeRNG 管理面板登录</h1>
            <p class="subtitle">管理面板登录</p>
            <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="/admin/login">
                <?= $csrf_field ?>
                <div class="form-group">
                    <label>邮箱</label>
                    <input type="email" name="email" class="form-control" required autofocus placeholder="admin@example.com">
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="password" class="form-control" required placeholder="Enter password">
                </div>
                <?php if ($locked): ?>
                <div class="alert alert-error" class="mb-3">登录尝试过于频繁，请 <?= (int) ceil(($lock_seconds ?? 0) / 60) ?> 分钟后再试。</div>
                <?php endif; ?>
                <?php if ($captcha_enabled): ?>
                <div class="form-group" class="flex gap-10 align-end">
                    <div class="flex-1">
                        <label>验证码</label>
                        <input type="text" name="captcha" class="form-control" required maxlength="5" placeholder="输入图中字符" autocomplete="off">
                    </div>
                    <img src="/admin/captcha" alt="验证码" title="点击刷新" class="captcha-img" data-refresh-captcha>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-full" <?= $locked ? 'disabled' : '' ?>>登录</button>
            </form>
        </div>
    </div>
    <script src="/public/js/helpers.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
