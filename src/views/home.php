<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <!-- Hero + main content (a11y: <main>, h1 + sr-only keeps SEO while banner visually carries the title) -->
    <main id="main-content">
    <section class="hero">
        <h1 class="sr-only"><?= h($siteName) ?> - <?= h($siteSlogan) ?></h1>
        <div class="hero-inner">
        <div class="hero-grid">
            <!-- 左栏：品牌 banner + 文案 + CTA + 统计 -->
            <div class="hero-left">
                <!--: WebP preferred, PNG fallback, fetchpriority=high, preload above -->
                <picture class="hero-banner-wrap">
                    <source srcset="/assets/banner.webp" type="image/webp">
                    <img class="hero-banner" src="/assets/banner.png" width="1400" height="466" alt="<?= h($siteName) ?>随机二次元图片 API 服务" fetchpriority="high">
                </picture>
                <p class="hero-sub"><?= h($siteSlogan) ?>。真随机取图、多级分类、JSON 与重定向双模式——为二次元爱好者准备的治愈感，为开发者准备的效率感。</p>
                <div class="btn-group">
                    <a href="/docs" class="btn btn-primary btn-lg">API 文档</a>
                    <a href="/tester" class="btn btn-outline btn-lg">在线测试</a>
                    <a href="/admin" target="_blank" rel="noopener" class="btn btn-outline btn-lg">管理面板</a>
                </div>
                <div class="stats">
                    <div class="stat">
                        <div class="num stat-value" data-count="<?= (int)$totalImages ?>"><?= number_format($totalImages) ?></div>
                        <div class="label">图片资源</div>
                    </div>
                    <div class="stat">
                        <div class="num stat-value" data-count="<?= (int)$totalCategories ?>"><?= number_format($totalCategories) ?></div>
                        <div class="label">分类主题</div>
                    </div>
                    <div class="stat">
                        <div class="num">99.9%</div>
                        <div class="label">服务可用性</div>
                    </div>
                </div>
                <p class="stats-note">以上为实时统计，图片与分类数据随后台更新</p>
            </div>

            <!-- 右栏：抽图舞台（hero-stage 卡片化，设计核心交互） -->
            <div class="hero-stage">
                <!-- Random image demo: proves the API works right from the hero -->
                <div class="random-demo reveal" role="region" aria-label="随机图片生成器">
                    <div class="rd-preview">
                        <div class="rd-placeholder" id="rd-placeholder">点「试试手气」，从 API 随机取一张图</div>
                        <img id="rd-image" src="" alt="随机图片（点击查看大图）" class="rd-image-preview hidden">
                        <div class="rd-loading hidden" id="rd-loading"><span class="spinner"></span></div>
                    </div>
                    <div class="rd-meta hidden" id="rd-meta"></div>
                    <div class="rd-footer">
                        <label for="rd-category" class="sr-only">随机图分类筛选</label>
                        <select class="form-control rd-cat-select" id="rd-category">
                            <option value="">全部</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat->getSlug()) ?>"><?= h($cat->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary btn-sm" id="rd-run"><?= icon('dice', 16) ?> 试试手气</button>
                        <span class="rd-url" id="rd-url">-</span>
                        <button type="button" class="btn btn-outline btn-sm copy-btn" data-copy="rd-url" aria-label="复制图片链接" title="复制链接"><?= icon('copy', 16) ?></button>
                        <!-- v1.2.1 迭代: view-large / download / history for the random demo -->
                        <button type="button" class="btn btn-outline btn-sm hidden" id="rd-zoom" aria-label="查看大图" title="查看大图"><?= icon('eye', 16) ?></button>
                        <button type="button" class="btn btn-outline btn-sm hidden" id="rd-download" aria-label="下载图片" title="下载图片"><?= icon('download', 16) ?></button>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </section>

    <div class="container">
        <!-- Features -->
        <section class="feature-grid">
            <div class="feature-card reveal">
                <div class="icon"><?= icon('dice', 28) ?></div>
                <h3>真随机算法 <span class="badge badge-primary">核心</span></h3>
                <p>数据库级 ORDER BY RAND() 确保每次请求独立随机的图片，无缓存无重复规律</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('folder-tree', 28) ?></div>
                <h3>多级分类 <span class="badge badge-primary">核心</span></h3>
                <p>无限层级分类树，API 指定分类返回该分类及其子分类下随机图片</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('zap', 28) ?></div>
                <h3>高速响应</h3>
                <p>轻量 PHP 核心，零重型框架，API 平均响应时间 &lt; 50ms</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('shuffle', 28) ?></div>
                <h3>双模式返回</h3>
                <p>JSON 结构化数据或 302 重定向直接输出图片，灵活适配不同场景</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('shield', 28) ?></div>
                <h3>速率限制</h3>
                <p>令牌桶算法限流，分级配额，响应头实时返回剩余请求数</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('cloud', 28) ?></div>
                <h3>多存储实例</h3>
                <p>本地存储与 6 家对象存储（COS / OSS / S3 / OBS / 又拍云 / 七牛）官方 SDK 接入，多实例管理、签名直链与 CDN 加速</p>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
<div class="lb-overlay hidden" id="rd-lightbox" role="dialog" aria-modal="true" aria-label="图片大图预览">
    <img id="rd-lb-img" src="" alt="大图预览">
    <button type="button" class="lb-close" id="rd-lb-close" aria-label="关闭预览"><?= icon('x', 24) ?></button>
</div>
