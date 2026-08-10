#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""删除 nul 设备文件：\\?\ NT 命名空间路径绕过 Windows 保留设备名。"""
import os, subprocess

ROOT = r'D:\projects\2026-08-05-10-13-25'
nul = os.path.join(ROOT, 'nul')

if not os.path.exists(nul):
    print('nul already gone')
else:
    done = False
    # 1) Python os.remove 带 \\?\ 前缀
    for path in (r'\\?\\' + nul, nul):
        try:
            os.remove(path)
            print('os.remove OK via %r' % path[:60])
            done = True
            break
        except Exception as e:
            print('os.remove fail %r - %s %s' % (path[:60], type(e).__name__, str(e)[:70]))
    # 2) cmd del 带 \\?\ 前缀
    if not done:
        r = subprocess.run(['cmd', '/c', 'del', '/f', '/q', r'\\?\\' + nul],
                           capture_output=True, text=True)
        print('cmd del rc=%d out=%r' % (r.returncode, r.stdout.strip()[:60]))
        done = not os.path.exists(nul)
    # 3) PowerShell 带 \\?\ 前缀
    if not done:
        r = subprocess.run(['powershell', '-NoProfile', '-Command',
                            "Remove-Item -LiteralPath '\\\\?\\%s' -Force -ErrorAction SilentlyContinue" % nul],
                           capture_output=True, text=True)
        print('ps rc=%d' % r.returncode)
    print('nul exists:', os.path.exists(nul))
