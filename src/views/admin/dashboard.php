<?php include __DIR__ . '/helpers.php'; admin_header('Dashboard'); ?>

<div class="page-header">
    <h1>Dashboard</h1>
    <p>欢迎回到 MoeRNG 管理面板</p>
</div>

<div class="grid grid-4">
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--primary)"><?= icon('image', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_images'] ?>"><?= number_format($stats['total_images']) ?></div>
        <div class="stat-label">图片资源</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--accent)"><?= icon('folder-tree', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_categories'] ?>"><?= number_format($stats['total_categories']) ?></div>
        <div class="stat-label">分类数量</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--info)"><?= icon('users', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_users'] ?>"><?= number_format($stats['total_users']) ?></div>
        <div class="stat-label">管理员账号</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--warning)"><?= icon('key', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_api_keys'] ?>"><?= number_format($stats['total_api_keys']) ?></div>
        <div class="stat-label">API Keys</div>
    </div>
</div>

<div class="grid grid-2 mt-3 reveal">
    <div class="card">
        <h3 class="mb-2">快捷操作</h3>
        <div class="flex gap-2 flex-wrap">
            <a href="/admin/images" class="btn btn-primary btn-sm"><?= icon('image', 16) ?> 管理图片</a>
            <a href="/admin/categories" class="btn btn-outline btn-sm"><?= icon('folder-tree', 16) ?> 管理分类</a>
            <a href="/admin/settings" class="btn btn-outline btn-sm"><?= icon('settings', 16) ?> 系统设置</a>
            <a href="/admin/apikeys" class="btn btn-outline btn-sm"><?= icon('key', 16) ?> 生成 API Key</a>
        </div>
    </div>
    <div class="card">
        <h3 class="mb-2">API 端点</h3>
        <p class="text-muted mb-1"><code>GET /api/v1/random</code> - 随机图片</p>
        <p class="text-muted mb-1"><code>GET /api/v1/images</code> - 图片列表</p>
        <p class="text-muted mb-1"><code>GET /api/v1/categories</code> - 分类列表</p>
        <p class="text-muted"><code>GET /api/v1/stats</code> - 统计数据</p>
    </div>
</div>

<?php admin_footer(); ?>
