#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# 最终验证：剥离注释后扫描最新 v1.0.29 zip 内 sdk/aws/Aws 的真实代码引用 vs 白名单
import zipfile, re, os, glob

zips = sorted(glob.glob(r'D:\projects\2026-08-05-10-13-25\releases\MoeRNG-v1.0.30-*.zip'))
zip_path = zips[-1]
z = zipfile.ZipFile(zip_path)

def strip_comments(src):
    src = re.sub(r'/\*.*?\*/', ' ', src, flags=re.S)
    src = re.sub(r'//[^\n]*', ' ', src)
    src = re.sub(r'#[^\n]*', ' ', src)
    return src

refs = set()
for n in z.namelist():
    if n.startswith('sdk/aws/Aws/') and n.endswith('.php'):
        src = strip_comments(z.read(n).decode('utf-8', 'ignore'))
        for m in re.finditer('Aws\\\\([A-Za-z0-9_]+)\\\\', src):
            refs.add(m.group(1))

keep = set()
for n in z.namelist():
    if n.startswith('sdk/aws/Aws/'):
        top = n[len('sdk/aws/Aws/'):].split('/')[0]
        if '.' not in top:
            keep.add(top)

missing = sorted(r for r in refs if r not in keep and r != 'data')
print('zip:', os.path.basename(zip_path), '| Size: %.2f MB' % (os.path.getsize(zip_path) / 1048576))
print('真实代码引用 (%d): %s' % (len(refs), ', '.join(sorted(refs))))
print('保留目录 (%d): %s' % (len(keep), ', '.join(sorted(keep))))
print('MISSING:', ', '.join(missing) if missing else 'NONE (clean)')
