<?php require_once dirname(__DIR__) . '/partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>环境检测 - MoeRNG 安装向导</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <!-- v1.2.1-beta.3 迭代: Design Tokens 圆体字族（渐进增强） -->
    <link rel="stylesheet" href="/assets/fonts/fonts.css">
    <link rel="stylesheet" href="/public/css/style.css">
    <style>
        .install-container { max-width: 640px; margin: 40px auto; padding: 0 24px; }
        .install-header { text-align: center; margin-bottom: 40px; }
        .install-header h1 { color: var(--primary); font-size: 2.2rem; }
        .install-logo { display: block; width: 64px; height: 64px; margin: 0 auto 12px; border-radius: 14px; }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <h1>MoeRNG 安装向导</h1>
            <p class="text-muted">环境检测</p>
        </div>

        <div class="steps">
            <div class="step active"><div class="step-num">1</div>环境检测</div>
            <div class="step"><div class="step-num">2</div>数据库</div>
            <div class="step"><div class="step-num">3</div>管理员</div>
            <div class="step"><div class="step-num">4</div>存储</div>
            <div class="step"><div class="step-num">5</div>完成</div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error mb-3"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 class="mb-2">系统环境检测</h2>
            <p class="text-muted mb-3">检测服务器环境是否满足 MoeRNG 运行要求</p>
            <div class="check-list">
                <?php foreach ($checks as $check): ?>
                <div class="check-item <?= $check['status'] ? 'pass' : 'fail' ?>">
                    <span class="status-icon"><?= $check['status'] ? icon('check-circle', 18) : icon('x-circle', 18) ?></span>
                    <div class="info">
                        <div class="name"><?= h($check['name']) ?></div>
                        <div class="detail">当前: <?= h($check['current']) ?> / 需要: <?= h($check['required']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($allPassed): ?>
            <form method="POST" action="/install/step2" class="text-right mt-3">
                <button type="submit" class="btn btn-primary btn-lg">下一步：数据库配置</button>
            </form>
            <?php else: ?>
            <div class="alert alert-error mt-3">
                请先修复以上标红的项目，然后刷新页面重新检测。
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
