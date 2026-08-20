#!/usr/bin/env node
// 扫描 sdk/aws/Aws 保留文件里的 Aws\Xxx\ 引用，对照白名单目录，报告缺失
const fs = require('fs');
const path = require('path');

const root = 'sdk/aws/Aws';
const refs = new Set();
const files = [];
(function walk(d) {
  for (const f of fs.readdirSync(d)) {
    const p = path.join(d, f);
    if (fs.statSync(p).isDirectory()) walk(p);
    else if (p.endsWith('.php')) files.push(p);
  }
})(root);

for (const p of files) {
  const src = fs.readFileSync(p, 'utf8');
  const re2 = /Aws\\([A-Za-z0-9_]+)\\/g;   // 匹配 Aws\Namespace\
  let m;
  while ((m = re2.exec(src))) refs.add(m[1]);
}

const keepDirs = fs.readdirSync(root)
  .filter(d => fs.statSync(path.join(root, d)).isDirectory());
const missing = [...refs].filter(ns => !keepDirs.includes(ns) && ns !== 'data');

console.log('被引用的 Aws 子命名空间 (' + refs.size + '):');
console.log('  ' + [...refs].sort().join(', '));
console.log();
console.log('白名单保留目录 (' + keepDirs.length + '):');
console.log('  ' + keepDirs.sort().join(', '));
console.log();
console.log('MISSING（被引用但目录缺失）:', missing.length ? missing.join(', ') : 'NONE ✅');
