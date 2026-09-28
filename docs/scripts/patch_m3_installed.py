# -*- coding: utf-8 -*-
"""覆盖安装重置修复：config 生成文件移出版本控制 + 归档排除（config/*.php 永不进仓库/归档）"""
import io, os

ROOT = r"E:\Projects\DouBao\MoeRNG"

def load(p):
    with io.open(p, encoding='utf-8', newline='') as f:
        return f.read()

def save(p, t):
    nl = '\r\n' if '\r\n' in t else '\n'
    t = t.replace('\r\n', '\n').replace('\n', nl)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)

# ── 1. .gitignore：config 生成文件统一排除（database/signing_key 两条合并为 *.php）──
p = os.path.join(ROOT, '.gitignore')
t = load(p)
old = "src/config/database.php          # DB credentials (written by the installer)\nsrc/config/signing_key.php        # HMAC signing key (auto-generated on first use)"
new = "src/config/*.php                # install-state & credentials (written by the installer; never ship in repo/release)"
if old in t:
    t = t.replace(old, new, 1)
    save(p, t)
    print('[OK] .gitignore: src/config/*.php 统一排除')
else:
    # 容错：直接加一行
    line = "\nsrc/config/*.php                # install-state & credentials (written by the installer)"
    if 'src/config/*.php' not in t:
        t = t.rstrip() + line + '\n'
        save(p, t)
        print('[OK] .gitignore 追加 src/config/*.php')
    else:
        print('[SKIP] 已有 src/config/*.php')

# ── 2. build_release.py：归档排除 config/*.php（保留 .htaccess 保护文件）──
p = os.path.join(ROOT, 'docs', 'scripts', 'build_release.py')
t = load(p)
old = "        p = os.path.join(dp, fn)\n        arc = os.path.relpath(p, SRC).replace(os.sep, '/')\n        entries.append((p, arc))"
new = "        p = os.path.join(dp, fn)\n        arc = os.path.relpath(p, SRC).replace(os.sep, '/')\n        # 覆盖安装修复: config/*.php 是安装器生成的本地状态（installed 标志/凭据），\n        # 归档若携带 installed=false 的 app.php，覆盖部署会把安装状态重置回安装向导。\n        if arc.startswith('config/') and arc.endswith('.php'):\n            continue\n        entries.append((p, arc))"
if old in t:
    t = t.replace(old, new, 1)
    save(p, t)
    print('[OK] build_release.py 排除 config/*.php')
else:
    print('[FAIL] build_release.py 锚点')
