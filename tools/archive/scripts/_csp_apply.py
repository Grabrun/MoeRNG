import glob

# 保守映射：只映射不改变 display/布局语义的样式
# 注意替换顺序：长的先，短的（display:none 等）后
MAP = [
    ('style="display:flex;align-items:center;justify-content:space-between"', 'class="flex flex-between"'),
    ('style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap"', 'class="flex gap-12 flex-wrap mb-3"'),
    ('style="display:flex;gap:12px;align-items:center;margin-bottom:16px"', 'class="flex gap-12 mb-3"'),
    ('style="display:flex;gap:10px;align-items:flex-end"', 'class="flex gap-10 align-end"'),
    ('style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"', 'class="flex gap-8 flex-wrap"'),
    ('style="display:flex;gap:8px;align-items:center"', 'class="flex gap-8"'),
    ('style="display:flex;gap:6px;align-items:center;cursor:pointer"', 'class="flex gap-6 align-center pointer"'),
    ('style="display:flex;gap:4px;flex-wrap:wrap"', 'class="flex gap-4 flex-wrap"'),
    ('style="display:flex;align-items:center;gap:8px"', 'class="flex gap-8"'),
    ('style="display:flex;align-items:flex-end"', 'class="flex align-end"'),
    ('style="display:inline"', 'class="inline"'),
    ('style="display:none"', 'class="hidden"'),
    ('style="font-size:0.85rem"', 'class="text-small"'),
    ('style="font-size:0.8rem"', 'class="text-xs"'),
    ('style="color:var(--text-secondary)"', 'class="text-secondary"'),
    ('style="font-size:0.85rem;margin-bottom:12px"', 'class="text-small mb-2"'),
    ('style="text-align:right"', 'class="text-right"'),
    ('style="font-family:monospace;font-size:0.85rem"', 'class="font-mono text-small"'),
    ('style="margin:0"', 'class="m-0"'),
    ('style="margin-left:6px"', 'class="ml-1"'),
    ('style="padding:10px"', 'class="p-10"'),
    ('style="padding:40px"', 'class="p-40"'),
    ('style="max-width:620px"', 'class="max-w-620"'),
    ('style="max-width:600px"', 'class="max-w-600"'),
    ('style="max-width:800px"', 'class="max-w-800"'),
    ('style="width:auto"', 'class="w-auto"'),
    ('style="width:100%;border-collapse:collapse"', 'class="table-full"'),
    ('style="flex:1"', 'class="flex-1"'),
    ('style="justify-content:flex-end"', 'class="flex justify-end"'),
    ('style="margin-bottom:0;cursor:pointer"', 'class="mb-0 pointer"'),
    ('style="margin-bottom:16px"', 'class="mb-3"'),
    ('style="border-top:1px solid var(--border);padding-top:12px"', 'class="border-top pt-3"'),
    ('style="color:var(--danger)"', 'class="text-danger"'),
    ('style="width:16px;height:16px"', 'class="icon-16"'),
    ('style="display:block;margin-top:8px;font-size:0.8rem;word-break:break-all"', 'class="block mt-2 text-xs wrap-all"'),
    ('style="width:100%;max-height:500px;object-fit:contain;border-radius:var(--radius-sm)"', 'class="img-preview"'),
    ('style="height:42px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer"', 'class="captcha-img"'),
    ('style="width:0%"', 'class="progress-fill" data-w="0"'),
]

# 追加的 CSS（补充工具类）
CSS_EXTRA = '''
/* v1.2.1 CSP 合规：通用布局/文字工具类 */
.gap-4 { gap: 4px; } .gap-6 { gap: 6px; } .gap-8 { gap: 8px; }
.gap-10 { gap: 10px; } .gap-12 { gap: 12px; }
.align-center { align-items: center; } .align-end { align-items: flex-end; }
.justify-end { justify-content: flex-end; }
.flex-wrap { flex-wrap: wrap; }
.inline { display: inline; }
.block { display: block; }
.pointer { cursor: pointer; }
.text-xs { font-size: 0.8rem; } .text-sm { font-size: 0.9rem; }
.text-secondary { color: var(--text-secondary); }
.text-danger { color: var(--danger); }
.m-0 { margin: 0; } .ml-1 { margin-left: 6px; } .ml-auto { margin-left: auto; }
.p-10 { padding: 10px; } .p-16 { padding: 16px; } .p-40 { padding: 40px; }
.py-2 { padding-top: 8px; padding-bottom: 8px; }
.max-w-320 { max-width: 320px; } .max-w-600 { max-width: 600px; }
.max-w-620 { max-width: 620px; } .max-w-800 { max-width: 800px; }
.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.w-200 { width: 200px; } .w-auto { width: auto; }
.flex-1 { flex: 1; }
.min-w-200 { min-width: 200px; } .min-w-220 { min-width: 220px; }
.table-full { width: 100%; border-collapse: collapse; }
.border-top { border-top: 1px solid var(--border); }
.pt-3 { padding-top: 12px; }
.icon-16 { width: 16px; height: 16px; }
.img-preview { width: 100%; max-height: 500px; object-fit: contain; border-radius: var(--radius-sm); }
.captcha-img { height: 42px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; }
.progress-fill { height: 100%; background: var(--primary); transition: width .3s ease; }
.alert-inline-danger { margin-bottom: 16px; padding: 12px 16px; border-radius: var(--radius-sm); background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3); color: var(--text); }
.alert-inline-success { margin-bottom: 16px; padding: 12px 16px; border-radius: var(--radius-sm); background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: var(--text); }
'''

views = [
    'src/views/admin/settings.php', 'src/views/admin/logs.php',
    'src/views/admin/storage.php', 'src/views/admin/images.php',
    'src/views/admin/apikeys.php', 'src/views/admin/categories.php',
    'src/views/admin/users.php', 'src/views/admin/login.php',
    'src/views/install/step4.php', 'src/views/install/step1.php',
    'src/views/install/step2.php', 'src/views/install/step3.php',
    'src/views/install/complete.php',
]
total = 0
for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    for a, b in MAP:
        s = s.replace(a, b)
    if s != orig:
        n = orig.count('style="') - s.count('style="')
        open(v, 'w', encoding='utf-8').write(s)
        print(f'{v}: -{n} (剩 {s.count(chr(34)*0+chr(115)+chr(116)+chr(121)+chr(108)+chr(101)+chr(61)+chr(34))})')
        total += n
print(f'共替换 {total} 处')

# 追加 CSS
css = open('src/public/css/style.css', encoding='utf-8').read()
css += CSS_EXTRA
open('src/public/css/style.css', 'w', encoding='utf-8').write(css)
print('CSS 工具类已追加')
