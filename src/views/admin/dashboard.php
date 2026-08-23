<?php
// 人类可读大小
function human_bytes($b) {
    $b = (float) $b;
    if ($b < 1024) return (int) $b . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    foreach ($units as $u) { $b /= 1024; if ($b < 1024) return round($b, 1) . ' ' . $u; }
    return round($b, 1) . ' PB';
}
// v1.2.0 迭代: defensive defaults — a partially-overwritten deploy must
// never 500; every block degrades gracefully.
$stats        ??= [];
$recentImages ??= [];
$categoryStats ??= [];
$usage        ??= ['count' => 0, 'total_bytes' => 0.0, 'avg_bytes' => 0.0, 'max_bytes' => 0.0];
$trend        ??= [];
$logs         ??= [];
$system       ??= ['php' => PHP_VERSION, 'timezone' => date_default_timezone_get(), 'storage' => null];
$apiStats     ??= ['total' => 0, 'today' => 0, 'week' => 0];
$visitStats   ??= ['total' => 0, 'today' => 0, 'week' => 0];
$apiSeries    ??= [];
$visitSeries  ??= [];
$status       ??= ['cpu' => null, 'mem' => null, 'disk' => null, 'php_mem' => null, 'mem_limit' => null];
?>
<?php include __DIR__ . '/helpers.php'; admin_header('仪表盘'); ?>

<div class="page-header">
    <h1>仪表盘</h1>
    <p>欢迎回到 MoeRNG 管理面板</p>
</div>

<!-- v1.2.1 UI 深度分析 (DASH-04): 快捷操作入口 -->
<div class="quick-actions reveal">
    <a href="/admin/images" class="action-btn">
        <span class="action-icon"><?= icon('upload', 22) ?></span>
        <span>上传图片</span>
    </a>
    <a href="/admin/categories" class="action-btn">
        <span class="action-icon"><?= icon('folder-tree', 22) ?></span>
        <span>管理分类</span>
    </a>
    <a href="/admin/storage" class="action-btn">
        <span class="action-icon"><?= icon('cloud', 22) ?></span>
        <span>存储管理</span>
    </a>
    <a href="/admin/settings" class="action-btn">
        <span class="action-icon"><?= icon('settings', 22) ?></span>
        <span>系统设置</span>
    </a>
    <a href="/admin/apikeys" class="action-btn">
        <span class="action-icon"><?= icon('key', 22) ?></span>
        <span>API Keys</span>
    </a>
    <a href="/admin/users" class="action-btn">
        <span class="action-icon"><?= icon('users', 22) ?></span>
        <span>用户管理</span>
    </a>
</div>

<!-- 1. 顶部统计卡 -->
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

<!-- 2. 最近上传 + 分类分布 -->
<div class="grid grid-2 mt-3 reveal">
    <div class="card">
        <div class="flex" style="justify-content:space-between;align-items:center">
            <h3 class="mb-2">最近上传</h3>
            <a href="/admin/images" class="text-muted" style="font-size:.85rem">查看全部 →</a>
        </div>
        <?php if (empty($recentImages)): ?>
            <p class="text-muted">暂无图片，去 <a href="/admin/images">图片管理</a> 上传第一张吧。</p>
        <?php else: ?>
        <!-- v1.2.1 修复: minmax(0,1fr) + min-width:0——grid 轨道默认 min-width:auto，
             大图固有宽度会把轨道撑到图片原始尺寸（卡片 3889px 溢出）；
             img 加 max-width 兜底 -->
        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px">
            <?php foreach ($recentImages as $img): ?>
            <a href="/admin/images" style="border-radius:var(--radius-sm);overflow:hidden;aspect-ratio:1;background:var(--bg-input);min-width:0;display:block" title="<?= h($img->original_name ?? '') ?>">
                <img src="<?= h($img->url()) ?>" alt="<?= h($img->original_name ?? '') ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;max-width:100%">
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

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

<!-- 3. 存储用量 + 7 天上传趋势 -->
<div class="grid grid-1 mt-3 reveal">
    <div class="card">
        <div class="flex" style="justify-content:space-between;align-items:baseline">
            <h3 class="mb-2">存储用量</h3>
            <span class="text-muted" style="font-size:.85rem">共 <?= (int)$usage['count'] ?> 张有效图片</span>
        </div>
        <div class="grid grid-3" style="text-align:center;margin-bottom:16px">
            <div>
                <div style="font-size:1.4rem;font-weight:600;color:var(--text)"><?= human_bytes($usage['total_bytes']) ?></div>
                <div class="stat-label">总占用</div>
            </div>
            <div>
                <div style="font-size:1.4rem;font-weight:600;color:var(--text)"><?= human_bytes($usage['avg_bytes']) ?></div>
                <div class="stat-label">平均大小</div>
            </div>
            <div>
                <div style="font-size:1.4rem;font-weight:600;color:var(--text)"><?= human_bytes($usage['max_bytes']) ?></div>
                <div class="stat-label">最大单图</div>
            </div>
        </div>
        <h4 class="mb-1" style="font-size:.95rem">近 7 天上传趋势</h4>
        <div style="display:flex;align-items:flex-end;gap:6px;height:90px;padding-top:8px">
            <?php $maxTrend = max(1, max(array_column($trend, 'count'))); ?>
            <?php foreach ($trend as $t): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
                <span style="font-size:.7rem;color:var(--text-secondary)"><?= (int)$t['count'] ?></span>
                <div style="width:100%;max-width:34px;height:<?= max(4, round($t['count'] / $maxTrend * 60)) ?>px;border-radius:6px 6px 0 0;background:linear-gradient(180deg,var(--primary),var(--accent))" title="<?= $t['day'] ?>: <?= (int)$t['count'] ?> 张"></div>
                <span style="font-size:.65rem;color:var(--text-muted)"><?= $t['day'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 4. 最近操作 -->
<div class="grid grid-1 mt-3 reveal">
    <div class="card">
        <h3 class="mb-2">最近操作</h3>
        <?php if (empty($logs)): ?>
            <p class="text-muted">暂无操作日志。</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:0">
            <?php foreach ($logs as $log): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:.88rem">
                <span style="background:var(--bg-input);color:var(--text-secondary);padding:2px 8px;border-radius:6px;white-space:nowrap"><?= h($log['action']) ?></span>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($log['detail'] ?: $log['username']) ?></span>
                <span style="color:var(--text-muted);font-size:.78rem;white-space:nowrap"><?= h(substr($log['time'], 5, 11)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 5. 流量统计（含总流量）+ 运行状态（合并系统概览与状态） -->
<div class="grid grid-2 mt-3 reveal">
    <!-- 流量统计 -->
    <div class="card">
        <div class="flex" style="justify-content:space-between;align-items:baseline">
            <h3 class="mb-2">流量统计</h3>
            <?php $grandTotal = (int)$apiStats['total'] + (int)$visitStats['total']; ?>
            <span style="font-size:.82rem;color:var(--text-muted)">总流量：<strong style="color:var(--primary)"><?= number_format($grandTotal) ?></strong> 次请求</span>
        </div>
        <!-- 概览：今日 + 累计（API+访问） -->
        <div class="grid grid-2" style="text-align:center;margin:8px 0 16px;padding:12px 0;border:1px solid var(--border);border-radius:var(--radius)">
            <div>
                <div style="font-size:1.3rem;font-weight:600;color:var(--primary)"><?= number_format((int)$apiStats['today'] + (int)$visitStats['today']) ?></div>
                <div class="stat-label">今日总请求</div>
            </div>
            <div>
                <div style="font-size:1.3rem;font-weight:600;color:var(--accent)"><?= number_format($grandTotal) ?></div>
                <div class="stat-label">累计总请求</div>
            </div>
        </div>
        <!-- API/访问 分项 -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:.88rem">
            <div style="padding:10px 12px;border-radius:var(--radius);background:var(--bg-input)">
                <div class="flex" style="justify-content:space-between"><span class="text-muted">API 调用</span><strong><?= number_format((int)$apiStats['today']) ?></strong></div>
                <div class="text-muted" style="font-size:.78rem">近 7 天 <?= number_format((int)$apiStats['week']) ?> · 累计 <?= number_format((int)$apiStats['total']) ?></div>
            </div>
            <div style="padding:10px 12px;border-radius:var(--radius);background:var(--bg-input)">
                <div class="flex" style="justify-content:space-between"><span class="text-muted">网站访问</span><strong><?= number_format((int)$visitStats['today']) ?></strong></div>
                <div class="text-muted" style="font-size:.78rem">近 7 天 <?= number_format((int)$visitStats['week']) ?> · 累计 <?= number_format((int)$visitStats['total']) ?></div>
            </div>
        </div>
        <h4 class="mb-1 mt-3" style="font-size:.95rem">近 7 天趋势（API / 访问）</h4>
        <div style="display:flex;align-items:flex-end;gap:6px;height:96px;padding-top:8px">
            <?php $maxFlow = max(1, max(array_column($apiSeries, 'count')), max(array_column($visitSeries, 'count'))); ?>
            <?php for ($i = 0; $i < 7; $i++): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">
                <div style="display:flex;gap:2px;align-items:flex-end;height:60px">
                    <div style="width:6px;height:<?= max(3, round($apiSeries[$i]['count'] / $maxFlow * 56)) ?>px;border-radius:3px 0 0 3px;background:var(--primary)" title="API <?= $apiSeries[$i]['day'] ?>: <?= $apiSeries[$i]['count'] ?>"></div>
                    <div style="width:6px;height:<?= max(3, round($visitSeries[$i]['count'] / $maxFlow * 56)) ?>px;border-radius:0 3px 3px 0;background:var(--accent)" title="访问 <?= $visitSeries[$i]['day'] ?>: <?= $visitSeries[$i]['count'] ?>"></div>
                </div>
                <span style="font-size:.65rem;color:var(--text-muted)"><?= $apiSeries[$i]['day'] ?></span>
            </div>
            <?php endfor; ?>
        </div>
        <div class="flex" style="gap:16px;margin-top:8px;font-size:.78rem;color:var(--text-secondary)">
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--primary);vertical-align:middle;margin-right:4px"></span>API 调用</span>
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--accent);vertical-align:middle;margin-right:4px"></span>网站访问</span>
        </div>
    </div>

    <!-- 运行状态（合并：原系统概览 + 系统状态） -->
    <div class="card">
        <h3 class="mb-2">运行状态</h3>
        <!-- 实时指标（CPU / PHP 进程内存 / 磁盘） -->
        <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:20px">
            <?php
            $metrics = [
                ['label' => 'CPU 负载', 'val' => $status['cpu'], 'color' => 'var(--primary)'],
                ['label' => 'PHP 内存', 'val' => $status['mem'], 'color' => 'var(--accent)'],
                ['label' => '磁盘占用', 'val' => $status['disk'], 'color' => 'var(--info)'],
            ];
            foreach ($metrics as $m):
                $show = $m['val'] !== null;
            ?>
            <div>
                <div class="flex" style="justify-content:space-between;margin-bottom:4px">
                    <span style="font-size:.88rem"><?= $m['label'] ?></span>
                    <span style="font-size:.88rem;color:var(--text-secondary)"><?= $show ? $m['val'] . ' %' : '不可用' ?></span>
                </div>
                <div style="height:10px;border-radius:999px;background:var(--bg-input);overflow:hidden">
                    <div style="width:<?= $show ? min(100, $m['val']) : 0 ?>%;height:100%;border-radius:999px;background:<?= $m['color'] ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-muted" style="margin-top:0;font-size:.78rem;margin-bottom:14px">CPU 为 1 分钟负载均值 / 核数；PHP 内存 = 当前进程 / memory_limit；磁盘 = 项目盘占用率。</p>
        <!-- 环境信息（原「系统概览」） -->
        <div style="border-top:1px solid var(--border);padding-top:14px;font-size:.88rem">
            <div class="flex" style="justify-content:space-between;padding:4px 0"><span class="text-muted">PHP 版本</span><span><?= h($system['php']) ?></span></div>
            <div class="flex" style="justify-content:space-between;padding:4px 0"><span class="text-muted">内存上限</span><span><?= h($status['mem_limit'] ?? '-') ?></span></div>
            <div class="flex" style="justify-content:space-between;padding:4px 0"><span class="text-muted">当前进程占用</span><span><?= human_bytes((float)($status['php_mem'] ?? 0)) ?></span></div>
            <div class="flex" style="justify-content:space-between;padding:4px 0"><span class="text-muted">时区</span><span><?= h($system['timezone']) ?></span></div>
        </div>
    </div>
</div>

<?php admin_footer(); ?>