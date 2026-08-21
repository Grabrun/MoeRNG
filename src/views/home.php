<?php require_once __DIR__ . '/partials/icons.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>var t=localStorage.getItem('moerng-theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);</script>
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <title><?= h($siteName) ?> - <?= h($siteSlogan) ?></title>
    <!--: PNG favicon first (modern browsers), ICO fallback, ?v= busts the browser's stubborn favicon cache -->
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=20260812">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=20260812">
    <meta name="description" content="<?= h($siteName) ?> - <?= h($siteSlogan) ?>。基于 RESTful 架构的随机二次元图片 API 服务，支持多分类、JSON 与重定向双模式。">
    <meta property="og:title" content="<?= h($siteName) ?> - <?= h($siteSlogan) ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="<?= h($siteName) ?>是一个基于 RESTful 架构的随机二次元图片 API 服务，支持多分类、JSON 与重定向双模式返回。">
    <meta property="og:url" content="<?= h('https://' . ($_SERVER['HTTP_HOST'] ?? 'images.grabrun.top') . '/') ?>">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:locale" content="zh_CN">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($siteName) ?> - <?= h($siteSlogan) ?>">
    <meta name="twitter:description" content="免费二次元图片随机获取 API 服务">
    <meta property="og:image" content="/assets/og-image.png">
    <!-- v1.2.1 迭代: JSON-LD structured data (WebApplication) -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "<?= h($siteName) ?>",
        "description": "随机二次元图片 API 服务，支持多分类、JSON 与重定向双模式",
        "url": "<?= h('https://' . ($_SERVER['HTTP_HOST'] ?? 'images.grabrun.top') . '/') ?>",
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "Web",
        "offers": { "@type": "Offer", "price": "0", "priceCurrency": "CNY" },
        "featureList": ["随机图片获取 API", "多级分类支持", "JSON/Redirect 双模式", "速率限制保护"]
    }
    </script>
    <!-- preload LCP banner so the hero paints immediately -->
    <link rel="preload" as="image" href="/assets/banner.webp" fetchpriority="high">
    <link rel="stylesheet" href="/public/css/style.css">
    <style>
        .hero { padding: 100px 24px 80px; }
        .hero h1 { font-size: 4rem; margin-bottom: 12px; }
        .hero .subtitle { font-size: 1.25rem; color: var(--text-secondary); margin-bottom: 8px; }
        .hero .stats { display: flex; gap: 32px; justify-content: center; margin: 32px 0; }
        /* v1.2.1 迭代: bolder stat numbers (UI audit V2) */
        .hero .stats .stat-value {
            font-size: 2.4rem; font-weight: 800; line-height: 1.15;
            background: linear-gradient(135deg, var(--primary), #ffb3d0);
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent; color: var(--primary);
        }
        .hero .stat { text-align: center; }
        .hero .stat .num { font-size: 2rem; font-weight: 700; color: var(--primary); }
        .hero .stat .label { font-size: 0.85rem; color: var(--text-muted); }
        .endpoint-path { 
            font-family: var(--font-mono); color: var(--accent);
            font-size: 1.1rem; word-break: break-all;
        }
        .param-table { width: 100%; margin: 12px 0; }
        .param-table td, .param-table th { font-size: 0.9rem; padding: 8px 12px; }
        .rate-tier { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 16px 0; }
        .rate-tier .tier-card { background: var(--bg-input); border-radius: var(--radius-sm); padding: 16px; text-align: center; border: 1px solid var(--border); }
        .rate-tier .tier-card .tier-name { font-weight: 600; margin-bottom: 4px; }
        .rate-tier .tier-card .tier-limit { font-size: 1.5rem; font-weight: 700; color: var(--primary); }
        /* v1.1.0-beta.7: fixed top navigation bar (brand left, links + theme right) */
        .site-nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 300;
            display: flex; align-items: center; gap: 24px;
            height: 56px; padding: 0 20px;
            background: color-mix(in srgb, var(--bg) 84%, transparent);
            border-bottom: 1px solid var(--border);
        }
        /* v1.2.0 迭代: mobile nav — collapse 5 links into a hamburger button
           so the fixed header stops hogging space and overlapping content. */
        .site-nav-menu > summary { display: none; cursor: pointer; list-style: none; }
        .site-nav-menu > summary::-webkit-details-marker { display: none; }
        .site-nav-menu > summary svg { display: block; }
        @media (max-width: 768px) {
            .site-nav { gap: 8px; }
            .site-nav-menu > summary {
                display: inline-flex; align-items: center; justify-content: center;
                width: 40px; height: 40px; border-radius: var(--radius-sm);
                color: var(--text); margin-left: auto;
            }
            .site-nav-menu > summary:hover { background: var(--bg-card); }
            .site-nav-links {
                position: absolute; top: 56px; left: 0; right: 0;
                background: var(--bg); border-bottom: 1px solid var(--border);
                padding: 12px 20px; display: none;
                flex-direction: column; align-items: stretch; gap: 4px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.25);
            }
            .site-nav-menu[open] .site-nav-links { display: flex; }
            .site-nav-links a { padding: 10px 12px; border-radius: var(--radius-sm); }
            .site-nav-links a:hover { background: var(--bg-card); }
        }
        .site-nav-brand {
            display: inline-flex; align-items: center; gap: 8px;
            text-decoration: none; color: var(--text);
            font-weight: 700; font-size: 1.1rem; white-space: nowrap;
            padding: 4px 6px; border-radius: var(--radius-sm);
            transition: opacity var(--transition);
        }
        .site-nav-brand:hover { opacity: 0.85; }
        .site-nav-brand img { max-height: 34px; max-width: 160px; object-fit: contain; display: block; }
        .site-nav-links { display: flex; align-items: center; gap: 2px; margin-left: auto; }
        .site-nav-links a {
            padding: 6px 12px; border-radius: var(--radius-sm);
            color: var(--text-secondary); text-decoration: none;
            font-size: 0.9rem; transition: all var(--transition); white-space: nowrap;
        }
        .site-nav-links a:hover { background: var(--bg-input); color: var(--text); }
        .site-nav-links a.active { color: var(--primary); font-weight: 600; }
        .site-nav .theme-toggle-float { position: static; border-radius: var(--radius-sm); box-shadow: none; }
        html { scroll-padding-top: 76px; }
        /* v1.1.1: nav wraps on small screens so all items stay reachable */
        @media (max-width: 640px) {
            .site-nav { gap: 8px; padding: 8px 12px; height: auto; min-height: 52px; flex-wrap: wrap; }
            .site-nav-brand { font-size: 1rem; }
            .site-nav-brand img { max-height: 28px; max-width: 120px; }
            .site-nav-links { order: 3; width: 100%; margin-left: 0; justify-content: flex-start; padding: 4px 0 2px; overflow-x: auto; }
            .site-nav-links a { padding: 5px 10px; font-size: 0.85rem; flex-shrink: 0; }
            .site-nav .theme-toggle-float { margin-left: auto; }
        }
        /* v1.1.1: hero hierarchy polish */
        .hero h1 { font-weight: 800; letter-spacing: -0.02em; }
        .hero .stats { gap: 40px; }
        .hero .stat .num { font-size: 2.2rem; font-weight: 800; }
        .hero .stat { transition: transform var(--transition); }
        .hero .stat:hover { transform: translateY(-2px); }
        .site-logo { filter: drop-shadow(0 2px 6px rgba(0,0,0,0.25)); }
        /* v1.1.1: docs & tester polish */
        .section-title { letter-spacing: -0.01em; }
        #tester .api-tester { background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; }
        @media (max-width: 768px) {
            .hero h1 { font-size: 2.5rem; }
            .hero { padding: 60px 16px 40px; }
        }
    </style>
</head>
<body>
    <div class="toast-container"></div>

    <!-- v1.2.1 迭代: skip link for keyboard / screen-reader users -->
    <a href="#main-content" class="skip-link">跳到主要内容</a>

    <!-- fixed top navigation bar -->
    <header class="site-nav">
        <a class="site-nav-brand" href="/" aria-label="返回<?= h($siteName) ?>首页" title="返回首页">
            <?php if (!empty($logoUrl)): ?>
            <img src="<?= h($logoUrl) ?>" alt="<?= h($siteName) ?> Logo">
            <?php else: ?>
            <span><?= h($siteName) ?></span>
            <?php endif; ?>
        </a>
        <details class="site-nav-menu" open>
            <summary aria-label="打开菜单"><?= icon('menu', 22) ?></summary>
            <nav class="site-nav-links">
                <a href="/" class="active">首页</a>
                <a href="/#docs">API 文档</a>
                <a href="/#tester">在线测试</a>
                <a href="/#about">关于</a>
                <?php if (!empty($githubUrl)): ?>
                <a href="<?= h($githubUrl) ?>" target="_blank" rel="noopener nofollow">GitHub</a>
                <?php endif; ?>
                <a href="/admin">管理面板</a>
            </nav>
        </details>
        <button type="button" class="theme-toggle theme-toggle-float" id="theme-toggle" aria-label="切换深浅主题" title="切换深浅主题">
            <span class="ic icon-sun"><?= icon('sun', 20) ?></span>
            <span class="ic icon-moon"><?= icon('moon', 20) ?></span>
        </button>
    </header>

    <!-- Hero + main content (a11y: <main>, h1 + sr-only keeps SEO while banner visually carries the title) -->
    <main id="main-content">
    <section class="hero">
        <h1 class="sr-only"><?= h($siteName) ?> - <?= h($siteSlogan) ?></h1>
        <!--: WebP preferred, PNG fallback, fetchpriority=high, preload above -->
        <picture>
            <source srcset="/assets/banner.webp" type="image/webp">
            <img class="hero-banner" src="/assets/banner.png" width="1400" height="466" alt="<?= h($siteName) ?>随机二次元图片 API 服务" fetchpriority="high">
        </picture>
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
        <div class="btn-group">
            <a href="#docs" class="btn btn-primary btn-lg">API 文档</a>
            <a href="#tester" class="btn btn-outline btn-lg">在线测试</a>
            <a href="/admin" class="btn btn-outline btn-lg">管理面板</a>
        </div>

        <!-- Random image demo: proves the API works right from the hero -->
        <div class="random-demo reveal" role="region" aria-label="随机图片生成器">
            <div class="rd-preview">
                <div class="rd-placeholder" id="rd-placeholder">点「试试手气」，从 API 随机取一张图</div>
                <img id="rd-image" src="" alt="随机图片（点击查看大图）" style="display:none" class="rd-image-preview">
                <div class="rd-loading hidden" id="rd-loading"><span class="spinner"></span></div>
            </div>
            <div class="rd-meta hidden" id="rd-meta" style="font-size:.82rem;color:var(--text-secondary);margin-top:8px"></div>
            <div class="rd-footer">
                <label for="rd-category" class="sr-only">随机图分类筛选</label>
                <select class="form-control" id="rd-category" style="width:150px;flex-shrink:0">
                    <option value="">全部</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat->getSlug()) ?>"><?= h($cat->name) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm" id="rd-run"><?= icon('dice', 16) ?> 试试手气</button>
                <span class="rd-url" id="rd-url">-</span>
                <button type="button" class="btn btn-outline btn-sm copy-btn" data-copy="rd-url" aria-label="复制图片链接" title="复制链接"><?= icon('copy', 16) ?></button>
                <!-- v1.2.1 迭代: view-large / download / history for the random demo -->
                <button type="button" class="btn btn-outline btn-sm" id="rd-zoom" aria-label="查看大图" title="查看大图" style="display:none"><?= icon('eye', 16) ?></button>
                <button type="button" class="btn btn-outline btn-sm" id="rd-download" aria-label="下载图片" title="下载图片" style="display:none"><?= icon('download', 16) ?></button>
            </div>
            <div class="rd-history" id="rd-history" style="display:none;margin-top:12px">
                <p class="text-muted" style="font-size:.82rem;margin-bottom:6px">最近记录</p>
                <div id="rd-history-list" style="display:flex;gap:6px;flex-wrap:wrap"></div>
            </div>
        </div>

        <!-- v1.2.1 迭代: lightbox for the random demo image -->
        <div class="lb-overlay hidden" id="rd-lightbox" role="dialog" aria-modal="true" aria-label="图片大图预览">
            <img id="rd-lb-img" src="" alt="大图预览">
            <button type="button" class="lb-close" id="rd-lb-close" aria-label="关闭预览"><?= icon('x', 24) ?></button>
        </div>
    </section>

    <div class="container">
        <!-- Features -->
        <section class="feature-grid">
            <div class="feature-card reveal">
                <div class="icon"><?= icon('dice', 28) ?></div>
                <h3>真随机算法</h3>
                <p>数据库级 ORDER BY RAND() 确保每次请求独立随机的图片，无缓存无重复规律</p>
            </div>
            <div class="feature-card reveal">
                <div class="icon"><?= icon('folder-tree', 28) ?></div>
                <h3>多级分类</h3>
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
                <h3>多存储驱动</h3>
                <p>本地存储与对象存储 (S3/OSS/COS) 可切换，支持 CDN 加速</p>
            </div>
        </section>

        <!-- API Documentation -->
        <section id="docs" class="section reveal">
            <h2 class="section-title">API 文档</h2>

            <div class="doc-endpoint">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/random</span>
                    <span class="text-muted" style="margin-left:auto">获取随机图片</span>
                </div>
                <div class="body">
                    <p class="mb-2">返回一张随机图片。可通过参数指定分类和返回格式。</p>
                    <h3 class="mb-1">请求参数</h3>
                    <table class="param-table">
                        <thead><tr><th>参数</th><th>类型</th><th>必需</th><th>默认值</th><th>说明</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><code>category</code></td>
                                <td>string</td>
                                <td>否</td>
                                <td>-</td>
                                <td>分类标识(slug)，指定后从该分类及子分类中随机返回</td>
                            </tr>
                            <tr>
                                <td><code>type</code></td>
                                <td>string</td>
                                <td>否</td>
                                <td><code>json</code></td>
                                <td>返回类型：<code>json</code> 返回结构化数据，<code>redirect</code> 302重定向至图片</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 class="mb-1">请求示例</h3>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?category=landscape&type=json"'>复制</button>
                        <pre><code>curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?category=landscape&type=json"</code></pre>
                    </div>

                    <h3 class="mb-1">JSON 响应示例</h3>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/2026/08/abc123def.png",
    "width": 1920,
    "height": 1080,
    "mime_type": "image/png",
    "file_size": 2048576,
    "category": "landscape"
  }
}'>复制</button>
                        <pre><code>{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/2026/08/abc123def.png",
    "width": 1920,
    "height": 1080,
    "mime_type": "image/png",
    "file_size": 2048576,
    "category": "landscape"
  }
}</code></pre>
                    </div>

                    <h3 class="mb-1">重定向模式</h3>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='curl -L -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?type=redirect"
# HTTP 302 → 图片直接输出'>复制</button>
                        <pre><code>curl -L -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?type=redirect"
# HTTP 302 → 图片直接输出</code></pre>
                    </div>
                </div>
            </div>

            <div class="doc-endpoint">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/images</span>
                    <span class="text-muted" style="margin-left:auto">图片列表（分页）</span>
                </div>
                <div class="body">
                    <h3 class="mb-1">请求参数</h3>
                    <table class="param-table">
                        <thead><tr><th>参数</th><th>类型</th><th>默认值</th><th>说明</th></tr></thead>
                        <tbody>
                            <tr><td><code>page</code></td><td>int</td><td>1</td><td>页码</td></tr>
                            <tr><td><code>limit</code></td><td>int</td><td>20</td><td>每页数量（最大100）</td></tr>
                            <tr><td><code>category</code></td><td>string</td><td>-</td><td>分类过滤</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="doc-endpoint">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/categories</span>
                    <span class="text-muted" style="margin-left:auto">分类列表</span>
                </div>
                <div class="body">
                    <p>返回完整分类树结构（嵌套JSON），包含所有分类及其子分类。</p>
                </div>
            </div>

            <div class="doc-endpoint">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/stats</span>
                    <span class="text-muted" style="margin-left:auto">服务统计</span>
                </div>
                <div class="body">
                    <p>返回图片总数、分类总数、版本号、当前存储驱动等信息。</p>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='{
  "success": true,
  "data": {
    "total_images": 1234,
    "total_categories": 15,
    "version": "<?= defined('APP_VERSION') ? APP_VERSION : '1.2.1-beta.1' ?>",
    "storage_driver": "local"
  }
}'>复制</button>
                    <pre><code>{
  "success": true,
  "data": {
    "total_images": 1234,
    "total_categories": 15,
    "version": "<?= defined('APP_VERSION') ? APP_VERSION : '1.2.1-beta.1' ?>",
    "storage_driver": "local"
  }
}</code></pre>
                    </div>
                </div>
            </div>
        </section>

        <!-- Rate Limits -->
        <section id="rate-limits" class="section reveal">
            <h2 class="section-title">速率限制</h2>
            <div class="card">
                <p class="mb-2">API 使用令牌桶算法实现速率限制，通过响应头返回实时配额信息：</p>
                <table class="param-table mb-3">
                    <thead><tr><th>响应头</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td><code>X-RateLimit-Limit</code></td><td>时间窗口内最大请求数</td></tr>
                        <tr><td><code>X-RateLimit-Remaining</code></td><td>当前窗口剩余请求数</td></tr>
                        <tr><td><code>X-RateLimit-Reset</code></td><td>窗口重置时间（Unix 时间戳）</td></tr>
                        <tr><td><code>Retry-After</code></td><td>限流后建议重试等待秒数（仅触发限流时返回）</td></tr>
                    </tbody>
                </table>
                <div class="rate-tier">
                    <div class="tier-card">
                        <div class="tier-name">匿名用户（IP 限流）</div>
                        <div class="tier-limit">60 <small>/ 分钟</small></div>
                    </div>
                    <div class="tier-card">
                        <div class="tier-name">API Key（默认配额）</div>
                        <div class="tier-limit">60 <small>/ 分钟</small></div>
                    </div>
                    <div class="tier-card">
                        <div class="tier-name">API Key（可自定义）</div>
                        <div class="tier-limit">∞ <small>可配置</small></div>
                    </div>
                </div>
                <p class="text-muted mt-1"><small>超限时返回 HTTP 429 状态码，响应体包含 <code>retry_after</code> 字段提示等待时间。</small></p>
            </div>
        </section>

        <!-- API Tester -->
        <section id="tester" class="section reveal">
            <h2 class="section-title">在线测试</h2>
            <div class="api-tester" id="api-tester">
                <div class="flex gap-2 flex-wrap mb-3">
                    <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0">
                        <label for="test-category">分类</label>
                        <select class="form-control" id="test-category">
                            <option value="">全部（随机）</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat->getSlug()) ?>"><?= h($cat->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0">
                        <label for="test-type">返回格式</label>
                        <select class="form-control" id="test-type">
                            <option value="json">JSON</option>
                            <option value="redirect">Redirect (图片直出)</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:flex-end">
                        <button class="btn btn-primary" id="test-run">Send Request</button>
                    </div>
                </div>
                <p class="mb-2"><strong>请求 URL：</strong> <code id="test-url" style="word-break:break-all">-</code>
                    <button type="button" class="copy-btn copy-btn-inline" data-copy="test-url" aria-label="复制 URL" title="复制 URL"><?= icon('copy', 14) ?></button>
                </p>
                <p class="mb-2"><strong>cURL：</strong> <code id="test-curl" style="word-break:break-all">-</code>
                    <button type="button" class="copy-btn copy-btn-inline" data-copy="test-curl" aria-label="复制 cURL" title="复制 cURL"><?= icon('copy', 14) ?></button>
                </p>
                <div class="preview-box" id="test-result">
                    <span class="text-muted">点击「Send Request」查看结果</span>
                </div>
            </div>
        </section>

        <!-- About (hardcoded; mirrors the GitHub repo About block) -->
        <section id="about" class="section reveal">
            <h2 class="section-title">关于</h2>
            <div class="about-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px">
                <div class="about-card" style="background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:20px">
                    <h3 class="mb-2">项目简介</h3>
                    <p class="text-muted" style="font-size:0.92rem;line-height:1.7"><?= h($siteName) ?> 是一个基于 PHP 8.4 + MySQL 的随机二次元图片 API 服务，提供 JSON 结构化数据与 302 重定向双模式返回，支持多级分类、API Key 鉴权与速率限制。</p>
                </div>
                <div class="about-card" style="background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:20px">
                    <h3 class="mb-2">技术特性</h3>
                    <ul class="text-muted" style="font-size:0.92rem;line-height:1.9;padding-left:18px;margin:0">
                        <li>轻量自研框架，零重型依赖</li>
                        <li>多级分类树，指定分类随机取图</li>
                        <li>对象存储接入：COS / OSS / AWS S3 / OBS</li>
                        <li>内置管理后台与操作审计</li>
                    </ul>
                </div>
                <div class="about-card" style="background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:20px">
                    <h3 class="mb-2">开放与许可</h3>
                    <p class="text-muted" style="font-size:0.92rem;line-height:1.7;margin-bottom:12px">本项目基于 MIT License 开源，欢迎提交 Issue 与 Pull Request。</p>
                    <?php if (!empty($githubUrl)): ?>
                    <a href="<?= h($githubUrl) ?>" target="_blank" rel="noopener nofollow" class="btn btn-sm btn-outline"><?= icon('external-link', 16) ?> GitHub 仓库</a>
                    <?php else: ?>
                    <p class="text-muted" style="font-size:0.85rem">仓库地址可在「系统设置 → 站点信息 → GitHub 仓库地址」中配置。</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="footer">
            <p><?= h($siteName) ?> <?= h($copyright) ?></p>
            <p class="mt-1">MoeRNG v<?= defined('APP_VERSION') ? APP_VERSION : '1.2.1-beta.1' ?> &mdash; Open-source under MIT License</p>
            <?php if (!empty($icpNumber)): ?>
            <p class="mt-1"><a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener" style="color:inherit;text-decoration:none"><?= h($icpNumber) ?></a></p>
            <?php endif; ?>
            <?php if (!empty($footerHtml)): ?>
            <p class="mt-1 footer-custom" style="white-space:pre-line"><?= h($footerHtml) ?></p>
            <?php endif; ?>
        </footer>
    </div>

    <script src="/public/js/app.js"></script>
    <script>
    // v1.1.0-beta.8: nav active state follows the clicked anchor
    (function () {
        var links = document.querySelectorAll('.site-nav-links a');
        links.forEach(function (a) {
            a.addEventListener('click', function () {
                links.forEach(function (x) { x.classList.remove('active'); });
                a.classList.add('active');
            });
        });
    })();
    // v1.2.1 迭代: collapse the mobile nav on small viewports (the open
    // attribute is needed on desktop so the native <details> shows the
    // links — the JS removes it on mobile so the hamburger toggle works).
    (function () {
        var menu = document.querySelector('.site-nav-menu');
        if (!menu) return;
        var sync = function () { menu.removeAttribute('open'); };
        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) sync();
        window.addEventListener('resize', function () {
            if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) sync();
        });
    })();
    </script>
</body>
</html>
