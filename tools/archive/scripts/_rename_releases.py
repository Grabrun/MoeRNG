#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# 重命名 releases 中不符合 SemVer 规范的文件 + 清理同版本中间产物
import os

REL = r'D:\projects\2026-08-05-10-13-25\releases'

renames = {
    'MoeRNG-debug-session.zip': 'MoeRNG-debug-session-20260805.zip',
    'MoeRNG-v1.0.21-升级说明.md': 'MoeRNG-v1.0.21-upgrade-notes-20260806.md',
    'MoeRNG-v1.0.26-COS规范对照检查报告.md': 'MoeRNG-v1.0.26-cos-compliance-report-20260808.md',
}

for old, new in renames.items():
    op = os.path.join(REL, old)
    np = os.path.join(REL, new)
    if not os.path.isfile(op):
        print('SKIP (absent):', old)
        continue
    if os.path.exists(np):
        print('SKIP (target exists):', old)
        continue
    os.rename(op, np)
    print('RENAMED:', old, '->', new)

# 同版本中间产物：保留每个版本最新时间戳的包，删除更早的重复
# （v1.0.29 三个、v1.0.33 两个 —— 这些是同一版本的中间打包，保留最新）
import re
by_version = {}
for f in os.listdir(REL):
    m = re.match(r'MoeRNG-v([\d.]+(?:-beta\.\d+)?)-(\d{8}-\d{6})\.zip', f)
    if m:
        by_version.setdefault(m.group(1), []).append(f)

for ver, files in sorted(by_version.items()):
    if len(files) <= 1:
        continue
    files.sort()
    for dup in files[:-1]:  # 保留最后一个（时间戳最大）
        p = os.path.join(REL, dup)
        os.remove(p)
        print('CLEANED duplicate:', dup)

print('--- releases final ---')
for f in sorted(os.listdir(REL)):
    print(' ', f)
