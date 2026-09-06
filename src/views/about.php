<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <!-- About (hardcoded; mirrors the GitHub repo About block) -->
        <section id="about" class="section reveal">
            <h2 class="section-title">关于</h2>
            <div class="about-grid">
                <div class="about-card">
                    <h3 class="mb-2">项目简介</h3>
                    <p class="text-muted about-text"><?= h($siteName) ?> 是一个基于 PHP 8.4 + MySQL 的随机二次元图片 API 服务，提供 JSON 结构化数据与 302 重定向双模式返回，支持多级分类、API Key 鉴权与速率限制。</p>
                </div>
                <div class="about-card">
                    <h3 class="mb-2">技术特性</h3>
                    <ul class="text-muted about-list">
                        <li>轻量自研框架，零重型依赖</li>
                        <li>多级分类树，指定分类随机取图</li>
                        <li>6 家对象存储官方 SDK，多存储实例配置、签名直链与 CDN 加速</li>
                        <li>内置管理后台：图片 / 分类 / 存储 / 用户 / API Key / 操作审计 / 备份</li>
                        <li>安全基线：CSP 防护、登录双维度锁定、存储凭据加密存储、全链路 CSRF 防护</li>
                    </ul>
                </div>
                <div class="about-card">
                    <h3 class="mb-2">开放与许可</h3>
                    <p class="text-muted about-text about-text-mb">本项目基于 MIT License 开源，欢迎提交 Issue 与 Pull Request。</p>
                    <?php if (!empty($githubUrl)): ?>
                    <a href="<?= h($githubUrl) ?>" target="_blank" rel="noopener nofollow" class="btn btn-sm btn-outline"><?= icon('external-link', 16) ?> GitHub 仓库</a>
                    <?php else: ?>
                    <p class="text-muted text-small">仓库地址可在「系统设置 → 站点信息 → GitHub 仓库地址」中配置。</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
