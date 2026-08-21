<?php include __DIR__ . '/helpers.php'; admin_header('图片管理'); ?>

<div class="page-header flex-between">
    <div>
        <h1>图片管理</h1>
        <p>共 <span id="image-total" data-total="<?= $total ?>"><?= number_format($total) ?></span> 张图片</p>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('upload-modal').classList.add('active')">上传图片</button>
        <button class="btn btn-outline btn-sm" id="select-all">全选本页</button>
        <button class="btn btn-outline btn-sm" id="select-all-all">全选</button>
    </div>
</div>

<!-- Search & Filter -->
<div class="card mb-3">
    <form method="GET" action="/admin/images" class="flex gap-2 flex-wrap">
        <input type="text" name="search" class="form-control" value="<?= h($search) ?>" placeholder="搜索文件名..." style="flex:1;min-width:200px">
        <select name="category_id" class="form-control" style="width:200px">
            <option value="">全部分类</option>
            <option value="0" <?= $categoryId==='0'?'selected':'' ?>>未分类</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat->id ?>" <?= (string)$categoryId===(string)$cat->id?'selected':'' ?>><?= h($cat->name) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">筛选</button>
        <?php if ($search || $categoryId !== ''): ?>
        <a href="/admin/images" class="btn btn-outline btn-sm">清除</a>
        <?php endif; ?>
    </form>
</div>

<!-- Image Grid -->
<div class="image-grid" id="sortable-container">
    <?php foreach ($images as $img): ?>
    <div class="image-item" draggable="true" data-id="<?= $img->id ?>" data-url="<?= h($img->url()) ?>" data-name="<?= h($img->original_name) ?>">
        <div class="checkbox"><?= icon('check', 16) ?></div>
        <div class="quick-actions">
            <button type="button" data-image-action="view" title="查看大图" aria-label="查看大图"><?= icon('eye', 16) ?></button>
            <button type="button" class="copy-btn" data-copy-text="<?= h($img->url()) ?>" title="复制链接" aria-label="复制链接"><?= icon('copy', 16) ?></button>
        </div>
        <img src="<?= h($img->url()) ?>" alt="<?= h($img->original_name) ?>" loading="lazy">
        <div class="overlay">
            <span><?= h(mb_strlen($img->original_name) > 20 ? mb_substr($img->original_name,0,20).'...' : $img->original_name) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Empty state (revealed after the last image is deleted) -->
<div id="image-empty" class="empty-state <?= empty($images) ? '' : 'hidden' ?>">
    <div class="empty-icon"><?= icon('image', 32) ?></div>
    <h3>暂无图片</h3>
    <p>点击「上传图片」开始添加你的第一张图，或直接拖拽文件到上传窗口</p>
    <div class="empty-actions">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('upload-modal').classList.add('active')"><?= icon('upload', 16) ?> 上传图片</button>
    </div>
</div>

<!-- Pagination (v1.2.0 迭代: per-page selector keeps search/category params) -->
<div class="pagination-wrap">
    <div class="pagination">
        <?php for ($i = 1; $i <= $lastPage; $i++): ?>
        <?php if ($i === $page): ?>
        <span class="active"><?= $i ?></span>
        <?php else: ?>
        <a href="?page=<?= $i ?>&per_page=<?= $perPage ?>&search=<?= urlencode($search) ?>&category_id=<?= urlencode($categoryId) ?>"><?= $i ?></a>
        <?php endif; ?>
        <?php endfor; ?>
    </div>
    <div class="per-page">
        <span class="text-muted">共 <?= number_format($total) ?> 张</span>
        <label>每页
            <select id="per-page-select" class="form-control">
                <?php foreach ([10, 20, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= (int)$perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
            条</label>
    </div>
</div>

<!-- Batch Bar -->
<div id="batch-bar" class="hidden">
    <span>已选 <strong class="count">0</strong> 项</span>
    <!-- v1.2.1 迭代: bulk re-categorize (admin UI audit I2) -->
    <select id="batch-category" class="form-control" style="width:auto" aria-label="批量修改分类">
        <option value="">批量改分类…</option>
        <option value="0">未分类</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= (int) $cat->id ?>"><?= h($cat->name) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-outline btn-sm" id="batch-categorize" disabled>应用</button>
    <button class="btn btn-danger btn-sm" id="batch-delete">批量删除</button>
    <button class="btn btn-outline btn-sm" id="clear-selection">取消选择</button>
</div>

<!-- Upload Modal -->
<div class="modal-overlay" id="upload-modal">
    <div class="modal" style="max-width:600px">
        <h2>上传图片</h2>
        <form method="POST" action="/admin/images/upload" enctype="multipart/form-data" id="upload-form">
            <?= $csrf_field ?>
            <div class="form-group">
                <label>目标分类</label>
                <select name="upload_category_id" class="form-control">
                    <option value="">未分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat->id ?>"><?= h($cat->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>存储方案 <small class="text-muted">（可临时切换，默认选中默认方案）</small></label>
                <select name="storage_profile_id" class="form-control">
                    <?php foreach ($storageProfiles as $sp): ?>
                    <option value="<?= (int) $sp->id ?>" <?= ($defaultProfile && (int) $defaultProfile->id === (int) $sp->id) ? 'selected' : '' ?>>
                        <?= h($sp->name) ?>（<?= h($sp->typeLabel()) ?>）
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="drop-zone" id="drop-zone"
                 data-post-max="<?= h(ini_get('post_max_size')) ?>"
                 data-upload-max="<?= h(ini_get('upload_max_filesize')) ?>">
                <div class="icon"><?= icon('upload', 40) ?></div>
                <p>拖拽图片到此处或点击上传</p>
                <p class="text-muted"><small>支持 JPG, PNG, GIF, WebP, BMP, SVG · 单文件 ≤ <?= h(ini_get('upload_max_filesize')) ?> · 单次请求 ≤ <?= h(ini_get('post_max_size')) ?></small></p>
                <input type="file" name="images[]" multiple accept="image/*" style="display:none">
            </div>
        </form>
        <div class="progress-bar mt-2" style="display:none">
            <div class="fill" style="width:0%"></div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('upload-modal').classList.remove('active')">取消</button>
            <button type="button" class="btn btn-primary" id="upload-submit">开始上传</button>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal-overlay" id="preview-modal">
    <div class="modal" style="max-width:800px">
        <div class="flex-between mb-2">
            <h2 id="preview-title">预览</h2>
            <button class="btn btn-outline btn-sm" aria-label="关闭预览" onclick="document.getElementById('preview-modal').classList.remove('active')"><?= icon('x', 16) ?></button>
        </div>
        <img id="preview-image" src="" style="width:100%;max-height:500px;object-fit:contain;border-radius:var(--radius-sm)">
        <a id="preview-link" href="#" target="_blank" rel="noopener" class="text-muted" style="display:block;margin-top:8px;font-size:0.8rem;word-break:break-all"></a>
    </div>
</div>

<!-- Lightbox (fullscreen preview) -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="图片预览">
    <button type="button" class="lb-btn lb-close" id="lb-close" aria-label="关闭预览"><?= icon('x', 20) ?></button>
    <button type="button" class="lb-btn lb-prev" id="lb-prev" aria-label="上一张"><?= icon('chevron-left', 22) ?></button>
    <button type="button" class="lb-btn lb-next" id="lb-next" aria-label="下一张"><?= icon('chevron-right', 22) ?></button>
    <img class="lb-image" id="lb-image" alt="">
    <div class="lb-meta">
        <span class="name" id="lb-name"></span>
        <span class="lb-count" id="lb-count"></span>
        <button type="button" class="btn btn-accent btn-sm" id="lb-copy"><?= icon('copy', 16) ?> 复制链接</button>
        <span class="lb-url" id="lb-url" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
    </div>
</div>

<?php admin_footer(); ?>
