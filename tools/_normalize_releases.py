#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""releases 统一测试版 SemVer 命名：
zip:   MoeRNG-vX.Y.Z-beta.N-YYYYMMDD-HHMMSS.zip
md:    MoeRNG-vX.Y.Z-beta.N-{type}-YYYYMMDD.md
规则：非 beta 一律补 -beta.1；同版本被取代的旧包删除（v1.0.33-104814 被 beta.1-105317 取代）。
"""
import os, re

REL = r'D:\projects\2026-08-05-10-13-25\releases'
renamed, deleted, skipped = [], [], []

ZIP = re.compile(r'^MoeRNG-v(\d+\.\d+\.\d+)-(\d{8}-\d{6})\.zip$')          # 无 beta 的 zip
MD  = re.compile(r'^MoeRNG-v(\d+\.\d+\.\d+)-([a-z-]+)-(\d{8})\.md$')       # 无 beta 的 md

for f in sorted(os.listdir(REL)):
    src = os.path.join(REL, f)

    m = ZIP.match(f)
    if m:
        new = 'MoeRNG-v%s-beta.1-%s.zip' % (m.group(1), m.group(2))
        dst = os.path.join(REL, new)
        if os.path.exists(dst):
            # 目标已存在（被取代的旧包）→ 删除源
            os.remove(src)
            deleted.append(f + '  ->  REMOVED (superseded by ' + new + ')')
            continue
        os.rename(src, dst)
        renamed.append('%s  ->  %s' % (f, new))
        continue

    m = MD.match(f)
    if m:
        new = 'MoeRNG-v%s-beta.1-%s-%s.md' % (m.group(1), m.group(2), m.group(3))
        dst = os.path.join(REL, new)
        if os.path.exists(dst):
            os.remove(src)
            deleted.append(f + '  ->  REMOVED (superseded)')
            continue
        os.rename(src, dst)
        renamed.append('%s  ->  %s' % (f, new))
        continue

    skipped.append(f)  # 已规范 / 非版本产物

print('=== RENAMED (%d) ===' % len(renamed))
for x in renamed:
    print(' ', x)
print('=== DELETED (%d) ===' % len(deleted))
for x in deleted:
    print(' ', x)
print('=== UNCHANGED (%d) ===' % len(skipped))
for x in skipped:
    print(' ', x)
