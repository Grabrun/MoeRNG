# -*- coding: utf-8 -*-
"""深度审计第 3 轮：settings.* 读取点 vs 写入点 → 孤儿设置键（代码级）"""
import io, os, re

ROOT = r"E:\Projects\DouBao\MoeRNG"
skip = {'.git', 'node_modules', 'releases', '.dsh', 'storage', 'public/storage', 'design', 'var', 'backups',
        '__pycache__', 'sdk'}

reads = {}   # key -> [(file:line)]
writes = {}  # key -> [(file:line)]

pat_get = re.compile(r"Config::get\(\s*['\"](settings|app)\.([a-z0-9_]+)['\"]", re.I)
pat_set = re.compile(r"Config::set\(\s*['\"](settings|app)\.([a-z0-9_]+)['\"]", re.I)
pat_save = re.compile(r"saveSetting|update_setting|settings\[['\"]([a-z0-9_]+)['\"]\]|['\"]([a-z0-9_]+)['\"]\s*=>\s*\$", re.I)

for dp, dns, fns in os.walk(os.path.join(ROOT, 'src')):
    dns[:] = [d for d in dns if d not in skip]
    for fn in fns:
        if not fn.endswith('.php'):
            continue
        p = os.path.join(dp, fn)
        rel = os.path.relpath(p, ROOT).replace(os.sep, '/')
        t = io.open(p, encoding='utf-8', errors='replace').read()
        for pat, store in ((pat_get, reads), (pat_set, writes)):
            for m in pat.finditer(t):
                line = t.count('\n', 0, m.start()) + 1
                key = m.group(1) + '.' + m.group(2)
                store.setdefault(key, []).append(f'{rel}:{line}')
        for m in pat_save.finditer(t):
            line = t.count('\n', 0, m.start()) + 1
            # 只记 settings 数组写入（粗），key 取第一个捕获组非空
            k = m.group(1) or m.group(2)
            if k:
                writes.setdefault('settings.' + k, []).append(f'{rel}:{line}')

print('=== Config::get 读取到的 settings/app 键（含写入点计数）===')
orphans = []
for k in sorted(reads):
    w = writes.get(k, [])
    if not w:
        orphans.append(k)
    print(f'  {k}: 读={len(reads[k])} 写={len(w)}')
    for r in reads[k][:4]:
        print(f'     读 {r}')
    for x in w[:4]:
        print(f'     写 {x}')
print()
print('=== 疑似孤儿键（只读不写，代码级）===')
for k in orphans:
    print(f'  {k}: {reads[k]}')
