#!/usr/bin/env node
// 扫描 release zip 内 sdk/aws/Aws 的 Aws\Xxx\ 引用 vs 白名单目录，报告缺失
const fs = require('fs');
const { execSync } = require('child_process');
const path = require('path');

const zipPath = process.argv[2];
if (!zipPath) { console.error('usage: node check_zip_aws.js <zip>'); process.exit(1); }

// 用 python 解出 zip 内 sdk/aws/Aws 的文件清单 + 内容（避免额外依赖）
const script = `
import zipfile, sys
z = zipfile.ZipFile(r'${zipPath.replace(/\\/g, '\\\\')}')
for n in z.namelist():
    if n.startswith('sdk/aws/Aws/') and n.endswith('.php'):
        print('FILE\t' + n)
        print(z.read(n).decode('utf-8', 'ignore'))
`;
const out = execSync(`C:/Users/Administrator/.workbuddy/binaries/python/versions/3.13.12/python.exe -c "${script}"`, { encoding: 'utf8', maxBuffer: 100 * 1024 * 1024 });

const refs = new Set();
let curFile = null;
for (const line of out.split('\n')) {
  if (line.startsWith('FILE\t')) { curFile = line.slice(5); continue; }
  if (curFile) {
    const re2 = /Aws\\([A-Za-z0-9_]+)\\/g;
    let m;
    while ((m = re2.exec(line))) refs.add(m[1]);
  }
}

// 从 zip 拿目录清单（顶层子目录）
const script2 = `
import zipfile
z = zipfile.ZipFile(r'${zipPath.replace(/\\/g, '\\\\')}')
dirs = set()
for n in z.namelist():
    if n.startswith('sdk/aws/Aws/'):
        rest = n[len('sdk/aws/Aws/'):]
        top = rest.split('/')[0]
        if '.' in top: continue  # 文件
        dirs.add(top)
print(' '.join(sorted(dirs)))
`;
const dirsOut = execSync(`C:/Users/Administrator/.workbuddy/binaries/python/versions/3.13.12/python.exe -c "${script2}"`, { encoding: 'utf8' });
const keepDirs = dirsOut.trim().split(/\s+/).filter(Boolean);

const missing = [...refs].filter(ns => !keepDirs.includes(ns) && ns !== 'data');
console.log('zip 内 Aws 子命名空间引用数:', refs.size);
console.log('zip 内保留目录数:', keepDirs.length);
console.log('保留目录:', keepDirs.sort().join(', '));
console.log();
console.log('MISSING（被引用但 zip 里没有）:', missing.length ? missing.join(', ') : 'NONE ✅');
