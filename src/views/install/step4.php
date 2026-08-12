<?php require_once dirname(__DIR__) . '/partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>存储配置 - MoeRNG 安装向导</title>
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
            <p class="text-muted">存储配置</p>
        </div>

        <div class="steps">
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>环境检测</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>数据库</div>
            <div class="step done"><div class="step-num"><?= icon('check', 14) ?></div>管理员</div>
            <div class="step active"><div class="step-num">4</div>存储</div>
            <div class="step"><div class="step-num">5</div>完成</div>
        </div>

        <div class="card">
            <h2 class="mb-2">存储驱动配置</h2>
            <p class="text-muted mb-3">选择图片存储方式，安装后可在后台随时切换</p>

            <form method="POST" action="/install/complete">
                <div class="form-group">
                    <label>存储驱动</label>
                    <select name="storage_driver" class="form-control" id="storage-driver-select" onchange="toggleStorageFields()">
                        <option value="local">本地存储（推荐）</option>
                        <option value="s3">对象存储 (S3/OSS/COS)</option>
                    </select>
                    <small>本地存储将图片保存在服务器 public/uploads/ 目录。对象存储使用云端服务，需配置 Access Key。</small>
                </div>

                <div id="local-fields">
                    <div class="form-group">
                        <label>本地存储路径</label>
                        <input type="text" name="storage_local_path" class="form-control" value="public/uploads" placeholder="public/uploads">
                    </div>
                    <div class="form-group">
                        <label>CDN 加速域名（可选）</label>
                        <input type="text" name="cdn_url" class="form-control" placeholder="https://cdn.example.com">
                    </div>
                </div>

                <div id="s3-fields" class="hidden">
                    <div class="form-group">
                        <label>默认上传服务商</label>
                        <select name="storage_default_provider" class="form-control">
                            <option value="cos">腾讯云 COS</option>
                            <option value="oss">阿里云 OSS</option>
                            <option value="aws">AWS S3</option>
                        </select>
                    </div>

                    <?php
                        $providerFields = \App\Storage\S3Driver::providerFieldDefs();
                        $allProviders = \App\Storage\S3Driver::providerList();
                    ?>
                    <?php foreach ($allProviders as $pid => $pname): ?>
                    <fieldset class="provider-card" style="border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin:0 0 16px;">
                        <legend style="padding:0 8px; font-weight:600;"><?= h($pname) ?> 凭据</legend>
                        <div class="grid grid-2">
                            <?php foreach ($providerFields as $fk => $fdef): ?>
                            <div class="form-group" style="<?= ($fdef['type'] ?? 'text') === 'password' ? '' : 'grid-column:1/-1' ?>">
                                <label><?= h($fdef['label']) ?></label>
                                <input type="<?= ($fdef['type'] ?? 'text') === 'password' ? 'password' : 'text' ?>" name="storage_provider[<?= h($pid) ?>][<?= h($fk) ?>]" class="form-control" placeholder="<?= h($fdef['placeholder'] ?? '') ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php endforeach; ?>
                </div>

                <div class="btn-group text-right">
                    <a href="/install/step3" class="btn btn-outline">上一步</a>
                    <button type="submit" class="btn btn-accent btn-lg">开始安装</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function toggleStorageFields() {
        const val = document.getElementById('storage-driver-select').value;
        document.getElementById('local-fields').classList.toggle('hidden', val !== 'local');
        document.getElementById('s3-fields').classList.toggle('hidden', val !== 's3');
    }
    </script>
</body>
</html>
