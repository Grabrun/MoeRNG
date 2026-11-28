<?php
/** 前台公共头；期望变量：$siteName, $siteSlogan, $logoUrl, $githubUrl, $activePage（可选 $pageTitle/$pageDesc）。 */
$activePage = $activePage ?? 'home';
$navItems = [
    'home'    => ['/', '首页'],
    'gallery' => ['/gallery', '图库'],
    'docs'    => ['/docs', 'API 文档'],
    'tester'  => ['/tester', '在线测试'],
    'about'   => ['/about', '关于'],
];
$fullTitle = $pageTitle ?? ($siteName . ' - ' . $siteSlogan);
$metaDesc = $pageDesc ?? ($siteName . ' - ' . $siteSlogan . '。多级分类、JSON 与 302 双模式，可自托管部署。');
// 优先配置的 base_url，其次消毒后的请求 Host（避免 og:url 被 Host 头污染）。
$siteBase = rtrim((string) (\App\Core\Application::getInstance()?->baseUrl() ?? ''), '/');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="csrf-token" content="<?= $csrf_token ?>">
    <title><?= h($fullTitle) ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=<?= ASSET_VER ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=<?= ASSET_VER ?>">
    <meta name="description" content="<?= h($metaDesc) ?>">
    <meta property="og:title" content="<?= h($fullTitle) ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="<?= h($metaDesc) ?>">
    <meta property="og:url" content="<?= h($siteBase . '/') ?>">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:locale" content="zh_CN">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($fullTitle) ?>">
    <meta property="og:image" content="/assets/og-image.png">
    <?php if (($activePage ?? '') === 'home'): ?>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "<?= h($siteName) ?>",
        "description": "随机二次元图片 API 服务，支持多分类、JSON 与重定向双模式",
        "url": "<?= h($siteBase . '/') ?>",
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "Web",
        "offers": { "@type": "Offer", "price": "0", "priceCurrency": "CNY" },
        "featureList": ["随机图片获取 API", "多级分类支持", "JSON/Redirect 双模式", "速率限制保护"]
    }
    </script>
    <!-- preload LCP banner -->
    <link rel="preload" as="image" href="/assets/banner.webp" fetchpriority="high">
    <?php endif; ?>
    <link rel="preconnect" href="https://miaoda.feishu.cn" crossorigin>
    <link rel="preconnect" href="https://sf3-scmcdn-cn.feishucdn.com" crossorigin>
    <link rel="dns-prefetch" href="https://miaoda.feishu.cn">
    <link rel="dns-prefetch" href="https://sf3-scmcdn-cn.feishucdn.com">
    <!-- 字体 CSS 异步化：media=print 不阻塞首屏，nonce 脚本立即切回 all（CSP 禁 inline onload）。 -->
    <link rel="stylesheet" href="https://miaoda.feishu.cn/fonts/css2?family=Noto+Serif+SC:wght@600;700&family=Noto+Sans+SC:wght@400;500;700&display=swap" id="font-css" media="print">
    <script<?= \App\Core\CspNonce::attr() ?>>
    try{var l=document.getElementById('font-css');if(l){l.media='all';}}catch(e){}
    </script>
    <link rel="stylesheet" href="/public/css/style.css?v=<?= ASSET_VER ?>">
</head>
<body>
    <div class="toast-container"></div>

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
                <?php foreach ($navItems as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>" class="<?= $activePage === $key ? 'active' : '' ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </details>
        <button type="button" class="theme-toggle theme-toggle-float" id="theme-toggle" aria-label="切换深浅主题" title="切换深浅主题">
            <span class="ic icon-sun"><?= icon('sun', 20) ?></span>
            <span class="ic icon-moon"><?= icon('moon', 20) ?></span>
        </button>
    </header>
