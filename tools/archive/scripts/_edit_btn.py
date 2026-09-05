p = 'src/views/admin/categories.php'
s = open(p, encoding='utf-8').read()

# 根节点编辑按钮 → data-edit-category JSON
old_root = '''                <button class="btn btn-outline btn-sm" onclick="editCategory(<?= $root['id'] ?>, '<?= h($root['name']) ?>', '<?= h($root['slug']) ?>', '<?= h($root['description'] ?? '') ?>', '<?= $root['parent_id'] ?? '' ?>', '<?= $root['sort_order'] ?? 0 ?>')">编辑</button>'''
new_root = '''                <button class="btn btn-outline btn-sm" data-edit-category='<?= h(json_encode([
                    'id' => (int)$root['id'],
                    'name' => (string)($root['name'] ?? ''),
                    'slug' => (string)($root['slug'] ?? ''),
                    'desc' => (string)($root['description'] ?? ''),
                    'parent_id' => $root['parent_id'] ?? '',
                    'sort_order' => (int)($root['sort_order'] ?? 0),
                ], JSON_UNESCAPED_UNICODE)) ?>'>编辑</button>'''
if old_root in s:
    s = s.replace(old_root, new_root, 1)
    print('OK root edit btn')
else:
    print('MISS root edit btn')

# 子节点编辑按钮（echo 拼接）
old_child = """                    echo '<button class="btn btn-outline btn-sm" onclick="editCategory(' . (int)$n['id'] . ', \\'' . h($n['name']) . '\\', \\'' . h($n['slug']) . '\\', \\'' . h($n['description'] ?? '') . '\\', \\'' . ($n['parent_id'] ?? '') . '\\', \\'' . ($n['sort_order'] ?? 0) . '\\')">编辑</button>';"""
new_child = """                    $editJson = json_encode([
                        'id' => (int)$n['id'],
                        'name' => (string)($n['name'] ?? ''),
                        'slug' => (string)($n['slug'] ?? ''),
                        'desc' => (string)($n['description'] ?? ''),
                        'parent_id' => $n['parent_id'] ?? '',
                        'sort_order' => (int)($n['sort_order'] ?? 0),
                    ], JSON_UNESCAPED_UNICODE);
                    echo '<button class="btn btn-outline btn-sm" data-edit-category="' . h($editJson) . '">编辑</button>';"""
if old_child in s:
    s = s.replace(old_child, new_child, 1)
    print('OK child edit btn')
else:
    print('MISS child edit btn')
    # 打印实际内容辅助
    import re
    m = re.search(r"echo '<button class=\"btn btn-outline btn-sm\" onclick=\"editCategory[^;]+;", s)
    print('  actual:', repr(m.group(0))[:200] if m else 'NONE')

open(p, 'w', encoding='utf-8').write(s)
