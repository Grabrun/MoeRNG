<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <section class="hero">
        <h1 class="sr-only"><?= h($siteName) ?> - <?= h($siteSlogan) ?></h1>
        <div class="hero-inner">
        <div class="hero-grid">
            <div class="hero-left">
                <span class="hero-kicker">自托管 · 随机取图 · 二次元图库</span>
                <picture class="hero-banner-wrap">
                    <source srcset="/assets/banner.webp" type="image/webp">
                    <img class="hero-banner" src="/assets/banner.png" width="1400" height="466" alt="<?= h($siteName) ?>随机二次元图片 API 服务" fetchpriority="high">
                </picture>
                <p class="hero-sub"><?= h($siteSlogan) ?>。一次请求换一张图：JSON 给前端渲染，302 直接当图片地址用。自托管部署，图片和接口都在自己手里。</p>
                <div class="btn-group">
                    <a href="/docs" class="btn btn-primary btn-lg">API 文档</a>
                    <a href="/tester" class="btn btn-outline btn-lg">在线测试</a>
                    <a href="/admin" target="_blank" rel="noopener" class="btn btn-outline btn-lg">管理面板</a>
                </div>
                <div class="stats">
                    <div class="stat">
                        <div class="num stat-value" data-count="<?= (int)$totalImages ?>"><?= number_format($totalImages) ?></div>
                        <div class="label">张图片</div>
                    </div>
                    <div class="stat">
                        <div class="num stat-value" data-count="<?= (int)$totalCategories ?>"><?= number_format($totalCategories) ?></div>
                        <div class="label">个分类</div>
                    </div>
                    <div class="stat">
                        <div class="num stat-value" title="近 7 天 API 请求成功率，4xx 计为可用"><?= $availability === null ? '—' : number_format($availability * 100, 1) . '%' ?></div>
                        <div class="label">近 7 天可用率</div>
                    </div>
                </div>
            </div>

            <div class="hero-stage">
                <div class="random-demo reveal" role="region" aria-label="随机图片生成器">
                    <div class="rd-preview">
                        <div class="rd-placeholder" id="rd-placeholder">点「试试手气」，随机取一张看看效果</div>
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
                        <button class="btn btn-primary btn-sm rd-run-btn" id="rd-run"><?= icon('dice', 16) ?> 试试手气</button>
                        <div class="rd-actions hidden" id="rd-actions">
                            <span class="rd-url" id="rd-url">-</span>
                            <button type="button" class="btn btn-outline btn-sm copy-btn" data-copy="rd-url" aria-label="复制图片链接" title="复制链接"><?= icon('copy', 16) ?></button>
                            <button type="button" class="btn btn-outline btn-sm hidden" id="rd-zoom" aria-label="查看大图" title="查看大图"><?= icon('eye', 16) ?></button>
                            <button type="button" class="btn btn-outline btn-sm hidden" id="rd-download" aria-label="下载图片" title="下载图片"><?= icon('download', 16) ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </section>

    <div class="container">
        <section class="feature-grid">
            <div class="feature-card reveal">
                <div class="icon"><?= icon('dice', 28) ?></div>
                <h3>随机取图</h3>
                <p>每次请求都在符合条件的图里独立抽一张，不轮播、不缓存，刷新多少次都不会拿到固定序列。</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('folder-tree', 28) ?></div>
                <h3>多级分类</h3>
                <p>分类可任意嵌套；指定父分类时，整棵子树一起参与随机。</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('zap', 28) ?></div>
                <h3>轻量内核</h3>
                <p>自研 PHP 内核，没有重型框架依赖。部署就是解压、建库、配伪静态三步。</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('shuffle', 28) ?></div>
                <h3>双模式返回</h3>
                <p>一个接口两种输出：JSON 给前端渲染，302 直接塞进 <code>&lt;img&gt;</code> 的 src。</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('shield', 28) ?></div>
                <h3>速率限制</h3>
                <p>可选 API Key 鉴权与按 IP / Key 的固定窗口限流，剩余额度通过 <code>X-RateLimit-*</code> 响应头返回。</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('cloud', 28) ?></div>
                <h3>多存储实例</h3>
                <p>本地存储，或 COS / OSS / S3 / OBS / 又拍云 / 七牛六家对象存储。多实例并存，签名直链，可挂 CDN。</p>
            </div>
        </section>
    </main>

    <div class="lb-overlay hidden" id="rd-lightbox" role="dialog" aria-modal="true" aria-label="图片大图预览">
        <img id="rd-lb-img" src="" alt="大图预览">
        <button type="button" class="lb-close" id="rd-lb-close" aria-label="关闭预览"><?= icon('x', 24) ?></button>
    </div>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
