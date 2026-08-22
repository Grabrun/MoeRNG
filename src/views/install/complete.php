<?php require_once dirname(__DIR__) . '/partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装完成 - MoeRNG</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <!-- v1.2.1-beta.3 迭代: Design Tokens 圆体字族（渐进增强） -->
    <link rel="stylesheet" href="/public/css/fonts.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="/public/css/style.css?v=<?= APP_VERSION ?>"></head>
<body>
    <div class="install-container">
        <div class="install-header">
            <img class="install-logo" src="/assets/logo.png" width="64" height="64" alt="MoeRNG Logo">
            <h1>MoeRNG</h1>
            <p class="text-muted">安装完成</p>
        </div>

        <div class="steps">
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>环境检测</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>数据库</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>管理员</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>存储</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>完成</div>
        </div>

        <div class="card text-center">
            <div style="margin-bottom:16px;color:var(--success)"><?= icon('check-circle', 64) ?></div>
            <h2 class="mb-2" style="color:var(--success)">安装成功！</h2>
            <p class="text-muted mb-3">MoeRNG 已成功安装并配置完毕。</p>

            <div class="alert alert-info mb-3">
                <strong>安全提示：</strong> 为了安全起见，建议删除或重命名 <code>/views/install/</code> 目录。
            </div>

            <div class="flex gap-2 justify-center mt-3">
                <a href="<?= h($homeUrl) ?>" class="btn btn-primary btn-lg">访问前台</a>
                <a href="<?= h($adminUrl) ?>" class="btn btn-accent btn-lg">进入后台</a>
            </div>

            <div class="mt-3">
                <p class="text-muted">API 端点:</p>
                <p><code>GET <?= h($homeUrl) ?>api/v1/random</code></p>
            </div>
        </div>
    </div>
    <script src="/public/js/helpers.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
