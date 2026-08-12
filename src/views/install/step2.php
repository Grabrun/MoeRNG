<?php require_once dirname(__DIR__) . '/partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库配置 - MoeRNG 安装向导</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <link rel="stylesheet" href="/public/css/style.css">
    <style>.install-container { max-width: 640px; margin: 40px auto; padding: 0 24px; } .install-header { text-align: center; margin-bottom: 40px; } .install-header h1 { color: var(--primary); font-size: 2.2rem; }</style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <img class="install-logo" src="/assets/logo.png" width="64" height="64" alt="MoeRNG Logo">
            <h1>MoeRNG 安装向导</h1>
            <p class="text-muted">数据库配置</p>
        </div>

        <div class="steps">
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>环境检测</div>
            <div class="step active"><div class="step-num">2</div>数据库</div>
            <div class="step"><div class="step-num">3</div>管理员</div>
            <div class="step"><div class="step-num">4</div>存储</div>
            <div class="step"><div class="step-num">5</div>完成</div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error mb-3"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 class="mb-2">MySQL 数据库配置</h2>
            <p class="text-muted mb-3">请输入 MySQL 8.0+ 数据库连接信息。请确保数据库已创建。</p>

            <form method="POST" action="/install/step3">
                <div class="form-group"><label>主机地址</label><input type="text" name="db_host" class="form-control" value="<?= h($db['host']) ?>" required></div>
                <div class="form-group"><label>端口</label><input type="number" name="db_port" class="form-control" value="<?= h($db['port']) ?>" required></div>
                <div class="form-group"><label>数据库名</label><input type="text" name="db_database" class="form-control" value="<?= h($db['database']) ?>" required placeholder="moerng"></div>
                <div class="form-group"><label>用户名</label><input type="text" name="db_username" class="form-control" value="<?= h($db['username']) ?>" required></div>
                <div class="form-group"><label>密码</label><input type="password" name="db_password" class="form-control" value="<?= h($db['password']) ?>"></div>

                <div class="btn-group text-right">
                    <a href="/install" class="btn btn-outline">上一步</a>
                    <button type="submit" class="btn btn-primary">测试连接并继续</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
