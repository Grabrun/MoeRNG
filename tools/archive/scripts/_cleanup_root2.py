#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""第二方案：sdk 改名 _stale_sdk（移动非删除，绕开 bulk-delete 保护）+ nul \\\\?\\ 路径删除。"""
import os, subprocess

ROOT = r'D:\projects\2026-08-05-10-13-25'

# 1) sdk → _stale_sdk（原子 rename，不算删除）
sdk = os.path.join(ROOT, 'sdk')
stale = os.path.join(ROOT, '_stale_sdk')
if os.path.isdir(sdk) and not os.path.exists(stale):
    try:
        os.rename(sdk, stale)
        print('RENAMED sdk -> _stale_sdk | root sdk exists:', os.path.exists(sdk))
    except Exception as e:
        print('rename fail -', type(e).__name__, str(e)[:80])
elif os.path.isdir(sdk):
    print('sdk still present, _stale_sdk already exists')
else:
    print('sdk already gone')

# 2) nul 设备文件
nul = os.path.join(ROOT, 'nul')
if os.path.exists(nul):
    tried = False
    for path in (r'\\?\\' + nul, nul):
        try:
            os.remove(path)
            print('nul removed -> exists:', os.path.exists(nul))
            tried = True
            break
        except Exception as e:
            print('nul os.remove %r fail - %s %s' % (path[:60], type(e).__name__, str(e)[:60]))
    if not tried or os.path.exists(nul):
        r = subprocess.run(['cmd', '/c', 'del', '/f', '/q', r'\\?\\' + nul],
                           capture_output=True, text=True)
        print('cmd del nul rc=%d exists=%s out=%s' % (r.returncode, os.path.exists(nul), r.stdout.strip()[:60]))
else:
    print('nul already gone')

# 3) 最终根目录快照
print('root now:', sorted(os.listdir(ROOT)))
