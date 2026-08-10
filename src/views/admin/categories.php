<?php include __DIR__ . '/helpers.php'; admin_header('分类管理'); ?>

<div class="page-header flex-between">
    <div>
        <h1>分类管理</h1>
        <p>管理图片分类层级结构</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openModal('category-modal')">新建分类</button>
</div>

<div class="card">
    <div class="category-tree" id="category-tree">
        <?php
        // Flatten the tree into an id -> name map so each item can show its
        // parent's display name instead of a raw parent_id.
        $parentNames = [];
        $moerng_collect = function($nodes) use (&$moerng_collect, &$parentNames) {
            foreach ($nodes as $n) {
                $parentNames[$n['id']] = $n['name'];
                if (!empty($n['children'])) $moerng_collect($n['children']);
            }
        };
        $moerng_collect($categories);
        ?>
        <?php function renderTree($nodes) { ?>
            <?php foreach ($nodes as $node): ?>
            <div class="category-tree-item">
                <div class="cat-info" style="flex:1;min-width:0">
                    <div class="cat-head">
                        <span class="cat-name"><?= h($node['name']) ?></span>
                        <small class="text-muted">(<?= h($node['slug']) ?>)</small>
                        <span class="text-muted">· 排序 <?= (int)($node['sort_order'] ?? 0) ?></span>
                    </div>
                    <?php if (!empty($node['description'])): ?>
                    <div class="cat-desc text-muted"><?= h($node['description']) ?></div>
                    <?php endif; ?>
                    <div class="cat-meta text-muted">
                        父类：<?= ($node['parent_id'] ?? null) ? h($parentNames[$node['parent_id']] ?? '未知') : '顶级分类' ?>
                    </div>
                </div>
                <div class="actions">
                    <button class="btn btn-outline btn-sm" onclick="editCategory(<?= $node['id'] ?>, '<?= h($node['name']) ?>', '<?= h($node['slug']) ?>', '<?= h($node['description'] ?? '') ?>', '<?= $node['parent_id'] ?? '' ?>', '<?= $node['sort_order'] ?? 0 ?>')">编辑</button>
                    <button type="button" class="btn btn-danger btn-sm" data-category-delete="<?= $node['id'] ?>" data-name="<?= h($node['name']) ?>">删除</button>
                </div>
            </div>
            <?php if (!empty($node['children'])): ?>
            <div style="padding-left:24px">
                <?php renderTree($node['children']); ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        <?php } ?>
        <?php renderTree($categories); ?>
        <?php if (empty($categories)): ?>
        <p class="text-center text-muted" style="padding:40px">暂无分类，点击「新建分类」创建第一个分类</p>
        <?php endif; ?>
    </div>
</div>

<!-- Category Modal -->
<div class="modal-overlay" id="category-modal">
    <div class="modal">
        <h2 id="category-modal-title">新建分类</h2>
        <form method="POST" action="/admin/categories/create" id="category-form">
            <?= $csrf_field ?>
            <input type="hidden" name="id" id="cat-id">
            <div class="form-group">
                <label>分类名称 *</label>
                <input type="text" name="name" id="cat-name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>标识 (Slug)</label>
                <input type="text" name="slug" id="cat-slug" class="form-control" placeholder="留空自动生成">
            </div>
            <div class="form-group">
                <label>描述</label>
                <textarea name="description" id="cat-desc" class="form-control"></textarea>
            </div>
            <div class="form-group">
                <label>父级分类</label>
                <select name="parent_id" id="cat-parent" class="form-control">
                    <option value="">无（顶级分类）</option>
                    <?php function renderOptions($nodes, $depth=0) { ?>
                        <?php foreach ($nodes as $node): ?>
                        <option value="<?= $node['id'] ?>"><?= str_repeat('-- ', $depth) . h($node['name']) ?></option>
                        <?php if (!empty($node['children'])) renderOptions($node['children'], $depth+1); ?>
                        <?php endforeach; ?>
                    <?php } ?>
                    <?php renderOptions($categories); ?>
                </select>
            </div>
            <div class="form-group">
                <label>排序</label>
                <input type="number" name="sort_order" id="cat-sort" class="form-control" value="0">
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-outline" onclick="closeModal('category-modal')">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCategory(id, name, slug, desc, parentId, sort) {
    document.getElementById('category-modal-title').textContent = '编辑分类';
    document.getElementById('cat-id').value = id;
    document.getElementById('cat-name').value = name;
    document.getElementById('cat-slug').value = slug;
    document.getElementById('cat-desc').value = desc;
    document.getElementById('cat-parent').value = parentId;
    document.getElementById('cat-sort').value = sort;
    document.getElementById('category-form').action = '/admin/categories/update';
    document.getElementById('category-modal').classList.add('active');
}

// Reset form for new category
document.querySelector('[onclick="openModal(\'category-modal\')"]').addEventListener('click', function() {
    document.getElementById('category-modal-title').textContent = '新建分类';
    document.getElementById('cat-id').value = '';
    document.getElementById('cat-name').value = '';
    document.getElementById('cat-slug').value = '';
    document.getElementById('cat-desc').value = '';
    document.getElementById('cat-parent').value = '';
    document.getElementById('cat-sort').value = '0';
    document.getElementById('category-form').action = '/admin/categories/create';
});
</script>

<?php admin_footer(); ?>
