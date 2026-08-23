p = 'src/views/admin/dashboard.php'
s = open(p, encoding='utf-8').read()
repl = [
    ('class="flex" style="justify-content:space-between;align-items:center"', 'class="flex flex-between"'),
    ('class="text-muted" style="font-size:.85rem"', 'class="text-muted text-small"'),
    ('class="grid grid-3" class="text-center" style="margin-bottom:16px"', 'class="grid grid-3 text-center" style="margin-bottom:16px"'),
    ('<div class="grid grid-3" class="text-center" style="margin-bottom:16px">', '<div class="grid grid-3 text-center" style="margin-bottom:16px">'),
    ('style="font-size:1.4rem;font-weight:600;color:var(--text)"', 'class="usage-num"'),
    ('class="vstack" style="gap:0"', 'class="vstack" style="gap:0"'),
    ('class="flex" style="justify-content:space-between;align-items:baseline"', 'class="flex flow-summary-head"'),
    ('<div style="padding:10px 12px;border-radius:var(--radius);background:var(--bg-input)">', '<div class="flow-box">'),
    ('class="flex" style="justify-content:space-between"', 'class="flex flex-between"'),
    ('class="text-muted" style="font-size:.78rem"', 'class="text-muted flow-note"'),
    ('class="mb-1 mt-3" style="font-size:.95rem"', 'class="mb-1 mt-3 text-small"'),
    ('<span style="font-size:.65rem;color:var(--text-muted)">', '<span class="flow-day">'),
    ('style="border-top:1px solid var(--border);padding-top:14px;font-size:.88rem"', 'class="env-list"'),
    ('class="flex" style="justify-content:space-between;padding:4px 0"', 'class="flex flex-between env-row"'),
    ('style="text-align:center"', 'class="text-center"'),
]
# 需要额外 CSS 类
extra_css = '''
.env-list { border-top: 1px solid var(--border); padding-top: 14px; font-size: 0.88rem; }
.env-row { padding: 4px 0; }
'''
for a, b in repl:
    n = s.count(a)
    if n:
        s = s.replace(a, b)
    else:
        print('MISS:', a[:60])
open(p, 'w', encoding='utf-8').write(s)
open('src/public/css/style.css', 'a', encoding='utf-8').write(extra_css)
print('剩余 inline style:', s.count('style="'))
