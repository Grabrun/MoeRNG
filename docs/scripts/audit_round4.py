# -*- coding: utf-8 -*-
"""深度审计第 4 轮：编码残留（BOM/非法 UTF-8）+ 敏感文件跟踪状态"""
import io, os, re

ROOT = r"E:\Projects\DouBao\MoeRNG"

print('=== A. 非 UTF-8 / BOM 文件 ===')
bad = []
for dp, dns, fns in os.walk(ROOT):
    dns[:] = [d for d in dns if d not in {'.git', 'node_modules', 'releases', 'storage',
                                          'public/storage', 'design', 'var', 'backups', '__pycache__'}]
    for fn in fns:
        p = os.path.join(dp, fn)
        raw = open(p, 'rb').read()
        if raw.startswith(b'\xef\xbb\xbf'):
            bad.append((os.path.relpath(p, ROOT), 'BOM'))
            continue
        if raw[:2] in (b'\xff\xfe', b'\xfe\xff'):
            bad.append((os.path.relpath(p, ROOT), 'UTF-16'))
            continue
        if not raw:
            bad.append((os.path.relpath(p, ROOT), 'ZERO-BYTES'))
            continue
        if fn.endswith(('.php', '.js', '.html', '.md', '.json', '.css', '.py', '.sql', '.txt', '.yml', '.yaml', '.ini')):
            try:
                raw.decode('utf-8')
            except UnicodeDecodeError:
                bad.append((os.path.relpath(p, ROOT), 'NON-UTF8'))
print(f'  {"无问题" if not bad else "发现 " + str(len(bad)) + " 个:"}')
for r, k in bad:
    print(f'    {r}: {k}')

print()
print('=== B. git 跟踪的敏感/构建文件 ===')
out = os.popen('cd /d %s && git ls-files' % ROOT.replace('/', '\\')).read().splitlines()
sensitive = [f for f in out if re.search(r'(credentials|\.env|token|secret|password|\.dsh/moerng|config/.*\.php)', f, re.I)]
print(f'  git 跟踪文件数: {len(out)}')
for f in sensitive:
    print(f'    {f}')
print('  reference/ 跟踪数:', sum(1 for f in out if f.startswith('reference/')))
print('  tools/archive 跟踪数:', sum(1 for f in out if f.startswith('tools/archive')))
