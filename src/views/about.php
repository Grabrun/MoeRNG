<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <section id="about" class="section reveal">
            <h2 class="section-title">关于</h2>
            <div class="about-grid">
                <div class="about-card">
                    <h3 class="mb-2">这个站是什么</h3>
                    <p class="text-muted about-text"><?= h($siteName) ?> 是自托管的随机二次元图片 API。PHP 8.4 + MySQL，支持 JSON 与 302 两种返回方式，内置多级分类、API Key 鉴权与限流。图片托管和随机接口在同一套系统里，不依赖第三方服务。</p>
                </div>
                <div class="about-card">
                    <h3 class="mb-2">技术特性</h3>
                    <ul class="text-muted about-list">
                        <li>自研轻量内核，无重型框架依赖，解压 + 建库即可运行</li>
                        <li>多级分类树：指定父分类时，整棵子树参与随机</li>
                        <li>六家对象存储官方 SDK（COS / OSS / S3 / OBS / 又拍云 / 七牛），多实例并存，签名直链可挂 CDN</li>
                        <li>管理后台覆盖图片、分类、存储、用户、API Key、操作审计与备份</li>
                        <li>安全基线：CSP、登录失败锁定、存储凭据 AES-256-GCM 加密、全链路 CSRF 防护</li>
                    </ul>
                </div>
                <div class="about-card">
                    <h3 class="mb-2">开源与许可</h3>
                    <p class="text-muted about-text about-text-mb">MIT License 开源，Issue 与 Pull Request 都欢迎。</p>
                    <?php if (!empty($githubUrl)): ?>
                    <a href="<?= h($githubUrl) ?>" target="_blank" rel="noopener nofollow" class="btn btn-sm btn-outline"><?= icon('external-link', 16) ?> GitHub 仓库</a>
                    <?php else: ?>
                    <p class="text-muted text-small">仓库地址未公开。可在后台「系统设置 → 站点信息」填写 GitHub 地址。</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
