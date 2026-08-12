<?php include __DIR__ . '/helpers.php'; admin_header('仪表盘'); ?>

<div class="page-header">
    <h1>仪表盘</h1>
    <p>欢迎回到 MoeRNG 管理面板</p>
</div>

<!-- v1.2.0 迭代: enhanced dashboard -->
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
        <div class="stat-label">API Keys（<?= (int)$stats['active_api_keys'] ?> 启用）</div>
    </div>
</div>

<div class="grid grid-2 mt-3 reveal">
    <!-- 最近上传 -->
    <div class="card">
        <div class="flex" style="justify-content:space-between;align-items:center">
            <h3 class="mb-2">最近上传</h3>
            <a href="/admin/images" class="text-muted" style="font-size:.85rem">查看全部 →</a>
        </div>
        <?php if (empty($recentImages)): ?>
            <p class="text-muted">暂无图片，去 <a href="/admin/images">图片管理</a> 上传第一张吧。</p>
        <?php else: ?>
        <div class="recent-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
            <?php foreach ($recentImages as $img): ?>
            <a href="/admin/images" class="recent-item" style="border-radius:var(--radius-sm);overflow:hidden;aspect-ratio:1;background:var(--bg-input)" title="<?= h($img->original_name ?? '') ?>">
                <img src="<?= h($img->url()) ?>" alt="<?= h($img->original_name ?? '') ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover">
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 分类分布 -->
    <div class="card">
        <h3 class="mb-2">分类分布</h3>
        <?php if (empty($categoryStats)): ?>
            <p class="text-muted">暂无分类。</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px">
            <?php $maxCount = max(1, $categoryStats[0]['count']); ?>
            <?php foreach (array_slice($categoryStats, 0, 8) as $cat): ?>
            <div style="display:flex;align-items:center;gap:10px">
                <span style="width:120px;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($cat['name']) ?></span>
                <div style="flex:1;height:8px;border-radius:999px;background:var(--bg-input);overflow:hidden">
                    <div style="width:<?= round($cat['count'] / $maxCount * 100) ?>%;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--primary),var(--accent))"></div>
                </div>
                <span style="width:40px;text-align:right;font-size:.85rem;color:var(--text-secondary)"><?= (int)$cat['count'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2 mt-3 reveal">
    <!-- 快捷操作 -->
    <div class="card">
        <h3 class="mb-2">快捷操作</h3>
        <div class="flex gap-2 flex-wrap">
            <a href="/admin/images" class="btn btn-primary btn-sm"><?= icon('image', 16) ?> 管理图片</a>
            <a href="/admin/categories" class="btn btn-outline btn-sm"><?= icon('folder-tree', 16) ?> 管理分类</a>
            <a href="/admin/settings" class="btn btn-outline btn-sm"><?= icon('settings', 16) ?> 系统设置</a>
            <a href="/admin/apikeys" class="btn btn-outline btn-sm"><?= icon('key', 16) ?> 生成 API Key</a>
        </div>
    </div>
    <!-- 系统概览 -->
    <div class="card">
        <h3 class="mb-2">系统概览</h3>
        <div style="display:flex;flex-direction:column;gap:6px;font-size:.9rem">
            <div class="flex" style="justify-content:space-between"><span class="text-muted">PHP 版本</span><span><?= h($system['php']) ?></span></div>
            <div class="flex" style="justify-content:space-between"><span class="text-muted">时区</span><span><?= h($system['timezone']) ?></span></div>
            <?php if ($system['storage']): ?>
            <div class="flex" style="justify-content:space-between">
                <span class="text-muted">存储驱动</span>
                <span><?= $system['storage']['is_local'] ? '本地存储' : ('对象存储 · ' . strtoupper(h($system['storage']['provider']))) ?></span>
            </div>
            <div class="flex" style="justify-content:space-between"><span class="text-muted">默认实例</span><span><?= h($system['storage']['name']) ?></span></div>
            <?php endif; ?>
            <div class="flex" style="justify-content:space-between"><span class="text-muted">未分类图片</span><span><?= number_format((int)$stats['unused_images']) ?></span></div>
        </div>
    </div>
</div>

<div class="grid grid-2 mt-3 reveal">
    <!-- API 端点 -->
    <div class="card">
        <h3 class="mb-2">API 端点</h3>
        <p class="text-muted mb-1"><code>GET /api/v1/random</code> - 随机图片</p>
        <p class="text-muted mb-1"><code>GET /api/v1/images</code> - 图片列表</p>
        <p class="text-muted mb-1"><code>GET /api/v1/categories</code> - 分类列表</p>
        <p class="text-muted"><code>GET /api/v1/stats</code> - 统计数据</p>
    </div>
    <div class="card">
        <h3 class="mb-2">帮助</h3>
        <p class="text-muted mb-1">查看 <a href="/#docs">API 文档</a> 了解全部参数</p>
        <p class="text-muted mb-1">在 <a href="/admin/storage">存储管理</a> 配置对象存储</p>
        <p class="text-muted">遇到问题运行 <code>doctor.php</code> 自检</p>
    </div>
</div>

<?php admin_footer(); ?>
