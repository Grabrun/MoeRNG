<?php require_once dirname(__DIR__) . '/partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员账号 - MoeRNG 安装向导</title>
    <link rel="stylesheet" href="/public/css/style.css">
    <style>.install-container { max-width: 640px; margin: 40px auto; padding: 0 24px; } .install-header { text-align: center; margin-bottom: 40px; } .install-header h1 { color: var(--primary); font-size: 2.2rem; }</style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <h1>MoeRNG 安装向导</h1>
            <p class="text-muted">管理员账号</p>
        </div>

        <div class="steps">
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>环境检测</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>数据库</div>
            <div class="step active"><div class="step-num">3</div>管理员</div>
            <div class="step"><div class="step-num">4</div>存储</div>
            <div class="step"><div class="step-num">5</div>完成</div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error mb-3"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 class="mb-2">创建管理员账号</h2>
            <p class="text-muted mb-3">设置用于登录后台管理面板的管理员账号</p>

            <form method="POST" action="/install/step4">
                <div class="form-group"><label>邮箱 *</label><input type="email" name="email" class="form-control" required placeholder="admin@example.com"></div>
                <div class="form-group"><label>用户名 *</label><input type="text" name="username" class="form-control" required placeholder="admin"></div>
                <div class="form-group"><label>密码 *</label><input type="password" name="password" class="form-control" required minlength="6"></div>
                <div class="form-group"><label>确认密码 *</label><input type="password" name="password_confirmation" class="form-control" required minlength="6"></div>

                <div class="btn-group text-right">
                    <a href="/install/step2" class="btn btn-outline">上一步</a>
                    <button type="submit" class="btn btn-primary">下一步：存储配置</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
