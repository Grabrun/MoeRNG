<?php
/**
 * 图库分组片段：首屏与「换一批」共用同一份模板（后者由 HomeController::galleryFeed 直接 require）。
 * 入参 $sections：name / pc / pe。
 */
require_once __DIR__ . '/icons.php';

$galleryGroups = [
    ['key' => 'pc', 'label' => '电脑端', 'hint' => '横图', 'sizes' => '(max-width: 639px) 46vw, (max-width: 1023px) 30vw, 340px'],
    ['key' => 'pe', 'label' => '移动端', 'hint' => '竖图', 'sizes' => '(max-width: 639px) 46vw, (max-width: 1023px) 30vw, 172px'],
];
?>
<?php foreach ($sections as $section): ?>
<div class="gallery-section">
    <h3 class="gallery-cat-title"><?= h($section['name']) ?> <span class="gallery-cat-count"><?= count($section['pc']) + count($section['pe']) ?> 张</span></h3>
    <?php foreach ($galleryGroups as $group): ?>
    <?php $images = $section[$group['key']]; ?>
    <div class="gallery-group gallery-group-<?= $group['key'] ?>">
        <div class="gallery-group-head">
            <span class="gallery-group-name"><?= $group['label'] ?></span>
            <span class="gallery-group-meta"><?= $group['hint'] ?> · <?= count($images) ?> 张</span>
        </div>
        <?php if (empty($images)): ?>
        <p class="gallery-group-empty">这个分类暂时没有<?= $group['hint'] ?>。</p>
        <?php else: ?>
        <div class="gallery-grid">
            <?php foreach ($images as $img): ?>
            <div class="gallery-card">
                <a href="<?= h($img->url()) ?>" target="_blank" rel="noopener" class="gallery-thumb" aria-label="查看原图：<?= h($img->original_name) ?>">
                    <?php $ss = $img->srcset('sm,md'); ?>
                    <img src="<?= h($img->displayUrl('sm')) ?>"
                         <?= $ss !== '' ? 'srcset="' . h($ss) . '" sizes="' . h($group['sizes']) . '"' : '' ?>
                         alt="<?= h($img->original_name) ?>" loading="lazy" decoding="async">
                </a>
                <div class="gallery-meta">
                    <span class="gallery-name" title="<?= h($img->original_name) ?>"><?= h(mb_strlen($img->original_name) > 18 ? mb_substr($img->original_name, 0, 18) . '…' : $img->original_name) ?></span>
                    <button type="button" class="copy-btn copy-btn-inline" data-copy-text="<?= h($img->url()) ?>" aria-label="复制图片链接" title="复制链接"><?= icon('copy', 14) ?></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
