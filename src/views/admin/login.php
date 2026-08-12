<?php require_once __DIR__ . '/../partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>var t=localStorage.getItem('moerng-theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);</script>
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <title>Login - MoeRNG Admin</title>
    <!-- v1.2.0 迭代: brand favicon on the login page too -->
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <link rel="stylesheet" href="/public/css/style.css">
    <style>
        .login-page { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
        .login-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 40px; width: 100%; max-width: 400px; }
        .login-card .login-logo { display: block; width: 72px; height: 72px; margin: 0 auto 12px; border-radius: 16px; object-fit: contain; }
        .login-card h1 { text-align: center; margin-bottom: 8px; color: var(--primary); }
        .login-card .subtitle { text-align: center; color: var(--text-secondary); margin-bottom: 32px; }
    </style>
</head>
<body>
    <button type="button" class="theme-toggle theme-toggle-float" id="theme-toggle" aria-label="切换深浅主题" title="切换深浅主题">
        <span class="ic icon-sun"><?= icon('sun', 20) ?></span>
        <span class="ic icon-moon"><?= icon('moon', 20) ?></span>
    </button>
    <div class="login-page">
        <div class="login-card">
            <img class="login-logo" src="/assets/logo.png" width="72" height="72" alt="MoeRNG Logo">
            <h1>MoeRNG</h1>
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
                <div class="alert alert-error" style="margin-bottom:16px">登录尝试过于频繁，请 <?= (int) ceil(($lock_seconds ?? 0) / 60) ?> 分钟后再试。</div>
                <?php endif; ?>
                <?php if ($captcha_enabled): ?>
                <div class="form-group" style="display:flex;gap:10px;align-items:flex-end">
                    <div style="flex:1">
                        <label>验证码</label>
                        <input type="text" name="captcha" class="form-control" required maxlength="5" placeholder="输入图中字符" autocomplete="off">
                    </div>
                    <img src="/admin/captcha" alt="验证码" title="点击刷新" style="height:42px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer" onclick="this.src='/admin/captcha?'+Date.now()">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-full" <?= $locked ? 'disabled' : '' ?>>登录</button>
            </form>
        </div>
    </div>
</body>
</html>
