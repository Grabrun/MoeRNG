#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""清理根目录冗余：sdk/（src 有字节级一致副本）+ nul 设备文件。"""
import os

ROOT = r'D:\projects\2026-08-05-10-13-25'

# 1) 根目录 sdk/（src/sdk 已字节级一致校验通过）
p = os.path.join(ROOT, 'sdk')
files = locked = 0
if os.path.isdir(p):
    for dp, dns, fns in os.walk(p, topdown=False):
        for f in fns:
            try:
                os.remove(os.path.join(dp, f)); files += 1
            except FileNotFoundError:
                pass
            except Exception:
                locked += 1
        for d in dns:
            try:
                os.rmdir(os.path.join(dp, d))
            except OSError:
                pass
    try:
        os.rmdir(p)
    except OSError as e:
        print('sdk root dir not removed:', e)
    print('sdk: removed %d files, %d locked, dir exists: %s' % (files, locked, os.path.exists(p)))
else:
    print('sdk: already absent')

# 2) nul 设备文件（NT \\?\ 命名空间绕过保留设备名）
nul = os.path.join(ROOT, 'nul')
if os.path.exists(nul):
    done = False
    for path in (r'\\?\\' + nul, nul):
        try:
            os.remove(path)
            print('nul removed via %r -> exists: %s' % (path, os.path.exists(nul)))
            done = True
            break
        except Exception as e:
            print('nul fail %r - %s %s' % (path, type(e).__name__, str(e)[:70]))
    if not done:
        # 最后手段：cmd del 用 NT 路径
        import subprocess
        r = subprocess.run(
            ['cmd', '/c', 'del', r'\\?\\' + nul],
            capture_output=True, text=True
        )
        print('cmd del rc=%d exists=%s %s' % (r.returncode, os.path.exists(nul), r.stderr.strip()[:80]))
else:
    print('nul: already absent')
