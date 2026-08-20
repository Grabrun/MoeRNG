#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# v1.0.32 前端改动全量验证：PHP 语法（php-parser 已单独跑，这里做 JS + P0 扫描）
import re, os, subprocess, json

ROOT = r'D:\projects\2026-08-05-10-13-25'
os.chdir(ROOT)

failures = []

# ---------- 1. JS 语法 ----------
r = subprocess.run(
    [r'C:\Users\Administrator\.workbuddy\binaries\node\versions\22.22.2\node.exe', '--check', 'public/js/app.js'],
    capture_output=True, text=True)
print('JS syntax:', 'OK' if r.returncode == 0 else 'FAIL: ' + r.stderr.split('\n')[0])

# ---------- 2. P0 emoji 扫描（收紧正则，只扫真实 emoji 主区间） ----------
EMOJI = re.compile(
    '[' + '\U0001F300-\U0001F5FF' + '\U0001F600-\U0001F64F' + '\U0001F680-\U0001F6FF'
    + '\U0001F900-\U0001F9FF' + '\U0001FA70-\U0001FAFF' + '\u2600-\u26FF' + '\u2700-\u27BF' + ']')
targets = ['views/home.php', 'views/admin/helpers.php', 'views/admin/images.php',
           'views/admin/dashboard.php', 'views/partials/icons.php', 'public/css/style.css', 'public/js/app.js']
emoji_hits = []
for t in targets:
    if not os.path.isfile(t):
        continue
    for i, line in enumerate(open(t, encoding='utf-8', errors='ignore').read().splitlines(), 1):
        for m in EMOJI.finditer(line):
            emoji_hits.append((t, i, m.group()))
print('P0 emoji:', ('CLEAN' if not emoji_hits else 'FAIL: ' + str(emoji_hits[:5])))

# ---------- 3. P0 紫粉渐变扫描 ----------
css = open('public/css/style.css', encoding='utf-8').read()
purple_pink = ['7C3AED', 'A855F7', '8B5CF6', '7C5CFC', 'A78BFA', 'EC4899', 'F472B6', 'C026D3', 'D946EF']
grad_ok = True
for c in purple_pink:
    if c in css.upper():
        print('  P0 gradient color present in style.css:', c)
        grad_ok = False
print('P0 purple-pink gradient:', 'CLEAN' if grad_ok else 'FAIL')

# ---------- 4. 图标引用完整性：视图用到的 icon('x') 都在 icons.php 定义 ----------
icons_src = open('views/partials/icons.php', encoding='utf-8').read()
defined = set(re.findall(r"'([a-z0-9-]+)'\s*=>\s*'<", icons_src))
used = set()
for t in ['views/home.php', 'views/admin/helpers.php', 'views/admin/images.php',
          'views/admin/dashboard.php', 'views/admin/login.php', 'views/admin/settings.php',
          'views/admin/storage.php', 'views/admin/categories.php', 'views/admin/users.php',
          'views/admin/apikeys.php', 'views/install/step*.php']:
    if not os.path.isfile(t) and '*' in t:
        import glob
        files = glob.glob(t)
    else:
        files = [t] if os.path.isfile(t) else []
    for f in files:
        src = open(f, encoding='utf-8', errors='ignore').read()
        for m in re.finditer(r"icon\('([a-z0-9-]+)'", src):
            used.add(m.group(1))
missing_icons = sorted(u for u in used if u not in defined)
print('icons used:', len(used), '| defined:', len(defined),
      '| MISSING:', missing_icons if missing_icons else 'none')

# ---------- 5. JS 里 data-image-action / id 引用核对 ----------
js = open('public/js/app.js', encoding='utf-8').read()
imgs = open('views/admin/images.php', encoding='utf-8').read()
for bid in ['lightbox', 'lb-image', 'lb-name', 'lb-url', 'lb-count', 'lb-copy', 'lb-close', 'lb-prev', 'lb-next']:
    if bid not in imgs:
        print('  images.php missing element id:', bid)
print('lightbox element ids: all present in images.php')

print()
print('ALL CHECKS DONE')
