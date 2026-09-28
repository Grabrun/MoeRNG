# -*- coding: utf-8 -*-
"""深度审计第 2 轮：src/ 过滤 + 调试残留 + 硬编码凭据 + 死设置键"""
import io, os, re

ROOT = r"E:\Projects\DouBao\MoeRNG"
skip = {'.git', 'node_modules', 'releases', '.dsh', 'storage', 'public/storage', 'design', 'var', 'backups', '__pycache__'}

def walk_files(root):
    for dp, dns, fns in os.walk(root):
        dns[:] = [d for d in dns if d not in skip]
        for fn in fns:
            yield os.path.join(dp, fn)

def read(p):
    return io.open(p, encoding='utf-8', errors='replace').read()

print('═══════ A. src/ 内旧版本号/旧符号残留（区分注释 vs 功能性）═══════')
pats = {
    r'1\.5\.0-beta\.1': '旧版本号',
    r'v1\.5\.0': '旧版本号(v)',
    'storage_s3_': '旧凭据键',
    'migrate': '迁移字眼',
    'legacy': 'legacy字眼',
    'cleanupLegacy': '旧清理',
    'local_root': '迁根门禁',
}
compiled = {k: re.compile(k, re.I) for k in pats}
func_hits = []
for p in walk_files(os.path.join(ROOT, 'src')):
    if not p.endswith(('.php', '.js', '.html')):
        continue
    rel = os.path.relpath(p, ROOT).replace(os.sep, '/')
    t = read(p)
    for k, pat in compiled.items():
        for m in pat.finditer(t):
            line = t.count('\n', 0, m.start()) + 1
            # 粗判是否注释行（去空白后以 // # * <!-- 开头）
            ln_start = t.rfind('\n', 0, m.start()) + 1
            prefix = t[ln_start:m.start()].lstrip()
            in_comment = prefix.startswith(('//', '#', '*', '<!--', '/*'))
            tag = '注释' if in_comment else '代码/字符串'
            # 排除本审计脚本自身
            if rel.startswith('docs/'):
                continue
            print(f'  [{tag}] {rel}:{line}: {k}')
            if tag == '代码/字符串' and k not in ('migrate', 'legacy'):
                func_hits.append((rel, line, k, m.group(0)))
print(f'  → 功能性嫌疑 {len(func_hits)} 处')

print()
print('═══════ B. 调试残留（全库）═══════')
dbg = re.compile(r'\b(var_dump|print_r|die\s*\(|dd\s*\(|console\.log|debugger\b|error_log\s*\(|trigger_error\s*\(|fwrite\s*\(\s*STDERR|vprintf)\s*\(', re.I)
n = 0
for p in walk_files(ROOT):
    if not p.endswith(('.php', '.js')):
        continue
    rel = os.path.relpath(p, ROOT).replace(os.sep, '/')
    if 'jquery' in rel or rel.startswith('node_modules') or rel.startswith('.dsh'):
        continue
    t = read(p)
    for m in dbg.finditer(t):
        line = t.count('\n', 0, m.start()) + 1
        n += 1
        print(f'  {rel}:{line}: {m.group(1)}')
print(f'  → {n} 处')

print()
print('═══════ C. 硬编码凭据/密钥（src）═══════')
cred = re.compile(r"(sk-[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{16}|(?:access[_-]?key|secret[_-]?key|secret)\s*[:=]\s*['\"][^'\"]{8,}['\"]|password\s*[:=]\s*['\"][^'\"]{8,}['\"])", re.I)
n = 0
for p in walk_files(os.path.join(ROOT, 'src')):
    if not p.endswith(('.php', '.js')):
        continue
    rel = os.path.relpath(p, ROOT).replace(os.sep, '/')
    t = read(p)
    for m in cred.finditer(t):
        line = t.count('\n', 0, m.start()) + 1
        ctx = t[max(0, m.start()-50):m.end()+20].replace('\n', ' ')
        n += 1
        print(f'  {rel}:{line}: …{ctx}…')
print(f'  → {n} 处')
