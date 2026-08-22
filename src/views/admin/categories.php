<?php include __DIR__ . '/helpers.php'; admin_header('分类管理'); ?>

<div class="page-header flex-between">
    <div>
        <h1>分类管理</h1>
        <p>管理图片分类层级结构</p>
    </div>
    <div class="flex gap-2">
        <!-- v1.2.1 迭代: expand/collapse all (admin UI audit C1) -->
        <button type="button" class="btn btn-outline btn-sm" id="cat-expand-all">展开全部</button>
        <button type="button" class="btn btn-outline btn-sm" id="cat-collapse-all">折叠全部</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('category-modal')">新建分类</button>
    </div>
</div>

<div class="category-list" id="category-tree">
    <?php if (empty($categories)): ?>
    <div class="card"><p class="text-center text-muted" style="padding:40px">暂无分类，点击「新建分类」创建第一个分类</p></div>
    <?php else: ?>
    <?php foreach ($categories as $root): ?>
    <?php
        // Count total descendants for the root badge.
        $moerng_count = function ($n) use (&$moerng_count) {
            $c = 0;
            foreach ($n['children'] ?? [] as $ch) { $c += 1 + $moerng_count($ch); }
            return $c;
        };
        $descCount = $moerng_count($root);
        $kids = $root['children'] ?? [];
    ?>
    <div class="card cat-root-card">
        <div class="cat-root-header">
            <div class="cat-root-info">
                <strong class="cat-name"><?= h($root['name']) ?></strong>
                <small class="text-muted">(<?= h($root['slug']) ?>)</small>
                <span class="badge badge-info" style="margin-left:6px">顶级分类</span>
                <?php if ($descCount > 0): ?>
                <span class="text-muted" style="margin-left:6px">· <?= $descCount ?> 个子分类</span>
                <?php endif; ?>
                <span class="text-muted" style="margin-left:6px">· 排序 <?= (int)($root['sort_order'] ?? 0) ?></span>
            </div>
            <div class="actions">
                <button class="btn btn-outline btn-sm" onclick="editCategory(<?= $root['id'] ?>, '<?= h($root['name']) ?>', '<?= h($root['slug']) ?>', '<?= h($root['description'] ?? '') ?>', '<?= $root['parent_id'] ?? '' ?>', '<?= $root['sort_order'] ?? 0 ?>')">编辑</button>
                <button type="button" class="btn btn-danger btn-sm" data-category-delete="<?= $root['id'] ?>" data-name="<?= h($root['name']) ?>">删除</button>
            </div>
        </div>
        <?php if (!empty($root['description'])): ?>
        <div class="cat-desc text-muted"><?= h($root['description']) ?></div>
        <?php endif; ?>

        <?php if (!empty($kids)): ?>
        <div class="cat-children">
            <?php
            $moerng_render = function ($nodes, $depth) use (&$moerng_render) {
                foreach ($nodes as $n) {
                    echo '<div class="cat-child cat-depth-' . $depth . '">';
                    echo '<div class="cat-child-info">';
                    echo '<span class="cat-name">' . h($n['name']) . '</span>';
                    echo '<small class="text-muted">(' . h($n['slug']) . ')</small>';
                    echo '<span class="text-muted">· 排序 ' . (int)($n['sort_order'] ?? 0) . '</span>';
                    if (!empty($n['description'])) {
                        echo '<div class="cat-desc text-muted">' . h($n['description']) . '</div>';
                    }
                    echo '</div>';
                    echo '<div class="actions">';
                    echo '<button class="btn btn-outline btn-sm" onclick="editCategory(' . (int)$n['id'] . ', \'' . h($n['name']) . '\', \'' . h($n['slug']) . '\', \'' . h($n['description'] ?? '') . '\', \'' . ($n['parent_id'] ?? '') . '\', \'' . ($n['sort_order'] ?? 0) . '\')">编辑</button>';
                    echo '<button type="button" class="btn btn-danger btn-sm" data-category-delete="' . (int)$n['id'] . '" data-name="' . h($n['name']) . '">删除</button>';
                    echo '</div>';
                    echo '</div>';
                    if (!empty($n['children'])) {
                        echo '<div class="cat-grandchildren">';
                        $moerng_render($n['children'], $depth + 1);
                        echo '</div>';
                    }
                }
            };
            $moerng_render($kids, 1);
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
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
</div><?php admin_footer(); ?>
