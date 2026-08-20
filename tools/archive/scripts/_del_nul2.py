#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""nul 删除终极方案：先 rename 成普通文件名，再正常删除（trash 可处理普通文件）。"""
import os, subprocess

ROOT = r'D:\projects\2026-08-05-10-13-25'
nul = os.path.join(ROOT, 'nul')
tmp = os.path.join(ROOT, '_nul_tmp')

if not os.path.exists(nul):
    print('nul already gone')
    raise SystemExit(0)

# 1) rename nul -> _nul_tmp（移动非删除，绕开 trash 语义）
renamed = False
try:
    os.rename(nul, tmp)
    renamed = True
    print('renamed OK -> _nul_tmp')
except Exception as e:
    print('os.rename fail -', type(e).__name__, str(e)[:80])
if not renamed:
    r = subprocess.run(['cmd', '/c', 'ren', nul, '_nul_tmp'], capture_output=True, text=True)
    print('cmd ren rc=%d %s' % (r.returncode, r.stdout.strip()[:60]))
    renamed = not os.path.exists(nul) and os.path.exists(tmp)

# 2) 删除重命名后的普通文件
if renamed and os.path.exists(tmp):
    try:
        os.remove(tmp)
        print('_nul_tmp removed:', not os.path.exists(tmp))
    except Exception as e:
        print('_nul_tmp remove fail -', type(e).__name__, str(e)[:80])
elif renamed:
    print('_nul_tmp missing (renamed but not found?)')

print('final - nul exists:', os.path.exists(nul), '| _nul_tmp exists:', os.path.exists(tmp))
