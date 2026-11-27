<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <section id="gallery" class="section reveal">
            <div class="gallery-head">
                <div>
                    <h2 class="section-title mb-1">图库</h2>
                    <p class="text-muted mb-0">每个分类各 6 张：电脑端横图、移动端竖图。</p>
                </div>
                <?php if (!empty($sections)): ?>
                <button type="button" class="btn btn-outline btn-sm" id="gallery-refresh"><?= icon('shuffle', 16) ?> 换一批</button>
                <?php endif; ?>
            </div>

            <?php if (empty($sections)): ?>
            <div class="empty-state">
                <div class="empty-icon"><?= icon('image', 32) ?></div>
                <h3>图库还是空的</h3>
                <p class="text-muted">上传第一批图片后这里就有内容。入口在管理后台「图片管理」。</p>
            </div>
            <?php else: ?>
            <div id="gallery-sections">
                <?php require __DIR__ . '/partials/gallery_sections.php'; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
