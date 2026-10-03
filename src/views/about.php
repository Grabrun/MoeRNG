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
                    <p class="text-muted about-text"><?= h($siteName) ?> 是一个自托管的随机二次元图片 API：基于 PHP 8.4 + MySQL，支持 JSON 与 302 双模式返回，内置多级分类、API Key 鉴权与速率限制——图片托管与随机接口，一站搞定。</p>
                </div>
                <div class="about-card">
                    <h3 class="mb-2">技术特性</h3>
                    <ul class="text-muted about-list">
                        <li>轻量自研框架、零重型依赖，部署即可用</li>
                        <li>多级分类树：指定分类，随机取该分类及全部子分类下的图片</li>
                        <li>6 家对象存储官方 SDK：多实例配置、签名直链与 CDN 加速</li>
                        <li>完整管理后台：图片 / 分类 / 存储 / 用户 / API Key / 操作审计 / 备份</li>
                        <li>安全基线：CSP 防护、登录双维度锁定、存储凭据加密、全链路 CSRF 防护</li>
                    </ul>
                </div>
                <div class="about-card">
                    <h3 class="mb-2">开放与许可</h3>
                    <p class="text-muted about-text about-text-mb">本项目基于 MIT License 开源，欢迎提交 Issue 与 Pull Request，一起把它做得更好。</p>
                    <?php if (!empty($githubUrl)): ?>
                    <a href="<?= h($githubUrl) ?>" target="_blank" rel="noopener nofollow" class="btn btn-sm btn-outline"><?= icon('external-link', 16) ?> GitHub 仓库</a>
                    <?php else: ?>
                    <p class="text-muted text-small">GitHub 仓库地址暂未公开，可在后台「系统设置 → 站点信息」中配置。</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
