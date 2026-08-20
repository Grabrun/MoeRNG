#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# 目录整理：运行时代码 → src/，开发脚本 → tools/（保持 zip 部署结构不变）
import os, shutil

ROOT = r'D:\projects\2026-08-05-10-13-25'
SRC = os.path.join(ROOT, 'src')
TOOLS = os.path.join(ROOT, 'tools')

os.makedirs(SRC, exist_ok=True)
os.makedirs(TOOLS, exist_ok=True)

# 运行时代码（部署所需）→ src/
runtime = [
    'app', 'views', 'public', 'config', 'sdk',          # 目录
    'schema.sql', 'bootstrap.php', 'admin.php', 'api.php',
    'index.php', 'install.php', 'doctor.php',
    'nginx.conf.example', '.htaccess', 'debug_session.php',
]
# 开发/运维脚本 → tools/
scripts = [
    '_package.py', '_check_api.py', '_check_aws_refs.js', '_check_frontend.py',
    '_check_zip_aws.js', '_check_zip_aws.py', '_fix_memory.py',
    '_note_version.py', '_rename_releases.py',
]

for name in runtime:
    src_path = os.path.join(ROOT, name)
    dst_path = os.path.join(SRC, name)
    if not os.path.exists(src_path):
        print('SKIP (absent):', name)
        continue
    if os.path.exists(dst_path):
        print('SKIP (target exists):', name)
        continue
    os.rename(src_path, dst_path)
    print('MOVED -> src/:', name)

for name in scripts:
    src_path = os.path.join(ROOT, name)
    dst_path = os.path.join(TOOLS, name)
    if not os.path.isfile(src_path):
        print('SKIP (absent):', name)
        continue
    if os.path.exists(dst_path):
        print('SKIP (target exists):', name)
        continue
    os.rename(src_path, dst_path)
    print('MOVED -> tools/:', name)

# 清理 Windows 设备残留文件 nul
nul = os.path.join(ROOT, 'nul')
if os.path.exists(nul):
    try:
        os.remove(nul)
        print('REMOVED:', nul)
    except Exception as e:
        print('KEEP nul:', e)

print('--- root final ---')
for f in sorted(os.listdir(ROOT)):
    print(' ', f)
