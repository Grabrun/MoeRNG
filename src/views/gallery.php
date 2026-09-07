<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <section id="gallery" class="section reveal">
            <h2 class="section-title">图库</h2>
            <p class="text-muted mb-3">按分类浏览站内图片 · 每个分类随机展示 12 张，刷新可换一批</p>

            <?php if (empty($sections)): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= icon('image', 32) ?></div>
                <h3>暂无图片</h3>
                <p class="text-muted">图库还是空的，先到管理后台上传几张吧。</p>
            </div>
            <?php else: ?>
            <?php foreach ($sections as $section): ?>
            <div class="gallery-section">
                <h3 class="gallery-cat-title"><?= h($section['name']) ?> <span class="gallery-cat-count"><?= count($section['images']) ?> 张</span></h3>
                <div class="gallery-grid">
                    <?php foreach ($section['images'] as $img): ?>
                    <div class="gallery-card">
                        <a href="<?= h($img->url()) ?>" target="_blank" rel="noopener" class="gallery-thumb" aria-label="查看原图：<?= h($img->original_name) ?>">
                            <img src="<?= h($img->url()) ?>" alt="<?= h($img->original_name) ?>" loading="lazy">
                        </a>
                        <div class="gallery-meta">
                            <span class="gallery-name" title="<?= h($img->original_name) ?>"><?= h(mb_strlen($img->original_name) > 18 ? mb_substr($img->original_name, 0, 18) . '…' : $img->original_name) ?></span>
                            <button type="button" class="copy-btn copy-btn-inline" data-copy-text="<?= h($img->url()) ?>" aria-label="复制图片链接" title="复制链接"><?= icon('copy', 14) ?></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
