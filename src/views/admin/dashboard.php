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
        <div class="stat-icon" class="c-primary"><?= icon('image', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_images'] ?>"><?= number_format($stats['total_images']) ?></div>
        <div class="stat-label">图片资源</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" class="c-accent"><?= icon('folder-tree', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_categories'] ?>"><?= number_format($stats['total_categories']) ?></div>
        <div class="stat-label">分类数量</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" class="c-info"><?= icon('users', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_users'] ?>"><?= number_format($stats['total_users']) ?></div>
        <div class="stat-label">管理员账号</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" class="c-warning"><?= icon('key', 24) ?></div>
        <div class="stat-value" data-count="<?= (int)$stats['total_api_keys'] ?>"><?= number_format($stats['total_api_keys']) ?></div>
        <div class="stat-label">API Keys（<?= (int)$stats['active_api_keys'] ?> 启用）</div>
    </div>
</div>

<!-- 2. 最近上传 + 分类分布 -->
<div class="grid grid-2 mt-3 reveal">
    <div class="card">
        <div class="flex flex-between">
            <h3 class="mb-2">最近上传</h3>
            <a href="/admin/images" class="text-muted" class="text-small">查看全部 →</a>
        </div>
        <?php if (empty($recentImages)): ?>
            <p class="text-muted">暂无图片，去 <a href="/admin/images">图片管理</a> 上传第一张吧。</p>
        <?php else: ?>
        <!-- v1.2.1 修复（第二轮）: img 绝对定位填充——之前 img height:100% 依赖
             a 的高度，而 a 默认 align-self:stretch 又依赖行高，行高被大图固有
             高度撑爆 → aspect-ratio:1 失效、图片溢出卡片。绝对定位让 img 脱离
             行高计算，a 的尺寸完全由 aspect-ratio:1 × 轨道宽度决定 -->
        <div class="recent-grid">
            <?php foreach ($recentImages as $img): ?>
            <a href="/admin/images" class="tile" title="<?= h($img->original_name ?? '') ?>">
                <img src="<?= h($img->url()) ?>" alt="<?= h($img->original_name ?? '') ?>" loading="lazy" >
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
        <div class="vstack">
            <?php $maxCount = max(1, $categoryStats[0]['count']); ?>
            <?php foreach (array_slice($categoryStats, 0, 8) as $cat): ?>
            <div class="cat-row">
                <span class="cat-name"><?= h($cat['name']) ?></span>
                <div class="cat-bar">
                    <div class="cat-bar-fill" data-w="<?= round($cat['count'] / $maxCount * 100) ?>"></div>
                </div>
                <span class="cat-count"><?= (int)$cat['count'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 3. 存储用量 + 7 天上传趋势 -->
<div class="grid grid-1 mt-3 reveal">
    <div class="card">
        <div class="flex" class="flex flow-summary-head">
            <h3 class="mb-2">存储用量</h3>
            <span class="text-muted text-small">共 <?= (int)$usage['count'] ?> 张有效图片</span>
        </div>
        <div class="grid grid-3 text-center mb-3">
            <div>
                <div class="usage-num"><?= human_bytes($usage['total_bytes']) ?></div>
                <div class="stat-label">总占用</div>
            </div>
            <div>
                <div class="usage-num"><?= human_bytes($usage['avg_bytes']) ?></div>
                <div class="stat-label">平均大小</div>
            </div>
            <div>
                <div class="usage-num"><?= human_bytes($usage['max_bytes']) ?></div>
                <div class="stat-label">最大单图</div>
            </div>
        </div>
        <h4 class="mb-1" class="text-small">近 7 天上传趋势</h4>
        <div class="trend-chart">
            <?php $maxTrend = max(1, max(array_column($trend, 'count'))); ?>
            <?php foreach ($trend as $t): ?>
            <div class="trend-col">
                <span class="trend-num"><?= (int)$t['count'] ?></span>
                <div class="trend-bar" data-h="<?= max(4, round($t['count'] / $maxTrend * 60)) ?>" title="<?= $t['day'] ?>: <?= (int)$t['count'] ?> 张"></div>
                <span class="trend-day"><?= $t['day'] ?></span>
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
        <div class="vstack vstack-0">
            <?php foreach ($logs as $log): ?>
            <div class="log-row">
                <span class="log-action"><?= h($log['action']) ?></span>
                <span class="log-detail"><?= h($log['detail'] ?: $log['username']) ?></span>
                <span class="log-time"><?= h(substr($log['time'], 5, 11)) ?></span>
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
        <div class="flex flow-summary-head">
            <h3 class="mb-2">流量统计</h3>
            <?php $grandTotal = (int)$apiStats['total'] + (int)$visitStats['total']; ?>
            <span class="text-small">总流量：<strong class="c-primary"><?= number_format($grandTotal) ?></strong> 次请求</span>
        </div>
        <!-- 概览：今日 + 累计（API+访问） -->
        <div class="grid grid-2" class="traffic-summary">
            <div>
                <div class="traffic-num primary"><?= number_format((int)$apiStats['today'] + (int)$visitStats['today']) ?></div>
                <div class="stat-label">今日总请求</div>
            </div>
            <div>
                <div class="traffic-num accent"><?= number_format($grandTotal) ?></div>
                <div class="stat-label">累计总请求</div>
            </div>
        </div>
        <!-- API/访问 分项 -->
        <div class="flow-grid">
            <div class="flow-box">
                <div class="flex" class="flex"><span class="text-muted">API 调用</span><strong><?= number_format((int)$apiStats['today']) ?></strong></div>
                <div class="text-muted" class="flow-note">近 7 天 <?= number_format((int)$apiStats['week']) ?> · 累计 <?= number_format((int)$apiStats['total']) ?></div>
            </div>
            <div class="flow-box">
                <div class="flex flex-between"><span class="text-muted">网站访问</span><strong><?= number_format((int)$visitStats['today']) ?></strong></div>
                <div class="text-muted flow-note">近 7 天 <?= number_format((int)$visitStats['week']) ?> · 累计 <?= number_format((int)$visitStats['total']) ?></div>
            </div>
        </div>
        <h4 class="mb-1 mt-3 text-small">近 7 天趋势（API / 访问）</h4>
        <div class="trend-chart-96">
            <?php $maxFlow = max(1, max(array_column($apiSeries, 'count')), max(array_column($visitSeries, 'count'))); ?>
            <?php for ($i = 0; $i < 7; $i++): ?>
            <div class="flow-col">
                <div class="flow-bars">
                    <div class="flow-bar" data-h="<?= max(3, round($apiSeries[$i]['count'] / $maxFlow * 56)) ?>" title="API <?= $apiSeries[$i]['day'] ?>: <?= $apiSeries[$i]['count'] ?>"></div>
                    <div class="flow-bar accent" data-h="<?= max(3, round($visitSeries[$i]['count'] / $maxFlow * 56)) ?>" title="访问 <?= $visitSeries[$i]['day'] ?>: <?= $visitSeries[$i]['count'] ?>"></div>
                </div>
                <span class="flow-day"><?= $apiSeries[$i]['day'] ?></span>
            </div>
            <?php endfor; ?>
        </div>
        <div class="flex" class="legend">
            <span><span class="legend-dot primary"></span>API 调用</span>
            <span><span class="legend-dot accent"></span>网站访问</span>
        </div>
    </div>

    <!-- 运行状态（合并：原系统概览 + 系统状态） -->
    <div class="card">
        <h3 class="mb-2">运行状态</h3>
        <!-- 实时指标（CPU / PHP 进程内存 / 磁盘） -->
        <div class="metric-stack">
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
                <div class="flex" class="metric-head">
                    <span class="metric-label"><?= $m['label'] ?></span>
                    <span class="metric-value"><?= $show ? $m['val'] . ' %' : '不可用' ?></span>
                </div>
                <div class="metric-track">
                    <div class="metric-fill" data-w="<?= $show ? min(100, $m['val']) : 0 ?>" data-bg="<?= $m['color'] ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-muted" class="foot-note">CPU 为 1 分钟负载均值 / 核数；PHP 内存 = 当前进程 / memory_limit；磁盘 = 项目盘占用率。</p>
        <!-- 环境信息（原「系统概览」） -->
        <div class="env-list">
            <div class="flex flex-between env-row"><span class="text-muted">PHP 版本</span><span><?= h($system['php']) ?></span></div>
            <div class="flex flex-between env-row"><span class="text-muted">内存上限</span><span><?= h($status['mem_limit'] ?? '-') ?></span></div>
            <div class="flex flex-between env-row"><span class="text-muted">当前进程占用</span><span><?= human_bytes((float)($status['php_mem'] ?? 0)) ?></span></div>
            <div class="flex flex-between env-row"><span class="text-muted">时区</span><span><?= h($system['timezone']) ?></span></div>
        </div>
    </div>
</div>

<?php admin_footer(); ?>