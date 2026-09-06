<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <section id="gallery" class="section reveal">
            <h2 class="section-title">图库</h2>
            <p class="text-muted mb-3">浏览站内全部图片 · 共 <?= number_format($total) ?> 张 · 第 <?= (int)$page ?>/<?= (int)$lastPage ?> 页</p>

            <?php if (empty($images)): ?>
            <div class="empty-state" style="text-align:center; padding:48px 0;">
                <h3>暂无图片</h3>
                <p class="text-muted">图库还是空的，先到管理后台上传几张吧。</p>
            </div>
            <?php else: ?>
            <div class="gallery-grid">
                <?php foreach ($images as $img): ?>
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

            <?php if ($lastPage > 1): ?>
            <!-- Windowed pagination: first/last always, current ±2, ellipsis between -->
            <div class="pagination-wrap">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="/gallery?page=1" aria-label="第一页">&laquo;</a>
                    <a href="/gallery?page=<?= $page - 1 ?>" aria-label="上一页">&lsaquo;</a>
                    <?php endif; ?>
                    <?php
                    $windowStart = max(1, $page - 2);
                    $windowEnd = min($lastPage, $page + 2);
                    if ($windowStart > 1) {
                        echo '<a href="/gallery?page=1">1</a>';
                        if ($windowStart > 2) echo '<span class="pg-ellipsis">…</span>';
                    }
                    for ($i = $windowStart; $i <= $windowEnd; $i++):
                        if ($i === $page): ?>
                        <span class="active"><?= $i ?></span>
                        <?php else: ?>
                        <a href="/gallery?page=<?= $i ?>"><?= $i ?></a>
                        <?php endif;
                    endfor;
                    if ($windowEnd < $lastPage) {
                        if ($windowEnd < $lastPage - 1) echo '<span class="pg-ellipsis">…</span>';
                        echo '<a href="/gallery?page=' . $lastPage . '">' . $lastPage . '</a>';
                    }
                    ?>
                    <?php if ($page < $lastPage): ?>
                    <a href="/gallery?page=<?= $page + 1 ?>" aria-label="下一页">&rsaquo;</a>
                    <a href="/gallery?page=<?= $lastPage ?>" aria-label="最后一页">&raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
