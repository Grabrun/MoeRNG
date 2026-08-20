#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""v1.1.1-beta.1：全站排版/UX 打磨 + 发版。"""
import re, os, glob, zipfile, subprocess

NODE = r'C:\Users\Administrator\.workbuddy\binaries\node\versions\22.22.2\node.exe'
NODE_PATH = r'C:\Users\Administrator\.workbuddy\binaries\node\workspace\node_modules'
PY = r'C:\Users\Administrator\.workbuddy\binaries\python\versions\3.13.12\python.exe'
ROOT = r'D:\projects\2026-08-05-10-13-25'

check_js = r'''
const parser = require('php-parser');
const fs = require('fs');
const eng = new parser.Engine({ parser: { extractDoc: false, php7: false }, ast: { withPositions: false } });
for (const f of ['src/views/home.php','src/views/admin/helpers.php']) {
  try { eng.parseCode(fs.readFileSync(f,'utf8')); console.log('OK  ', f); }
  catch(e) { console.log('FAIL', f, '-', e.message.split('\n')[0]); }
}
const files = ['src/public/css/style.css','src/views/home.php','src/views/admin/helpers.php'];
const emojiRe = /[\u{1F300}-\u{1F5FF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}\u{1F900}-\u{1F9FF}\u{1FA70}-\u{1FAFF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/u;
let hits = [];
for (const f of files) { const m = fs.readFileSync(f,'utf8').match(emojiRe); if (m) hits.push(f + ' -> ' + m[0]); }
console.log('emoji:', hits.length ? hits.join('; ') : 'CLEAN');
// 紫粉渐变检测（style.css 主色是粉红+青 brand，非 Indigo→Pink 渐变）
const css = fs.readFileSync('src/public/css/style.css','utf8');
const badGrad = /linear-gradient\([^)]*?(#7C3AED|#A855F7|#EC4899|#6366F1|#8B5CF6)[^)]*?\)/i;
console.log('indigo-pink gradient:', css.match(badGrad) ? 'FOUND' : 'CLEAN');
const s = fs.readFileSync('src/views/home.php','utf8');
console.log('nav wrap:', s.includes('flex-wrap: wrap') ? 'OK' : 'MISS');
console.log('hero polish:', s.includes('letter-spacing: -0.02em') ? 'OK' : 'MISS');
'''
env = dict(os.environ, NODE_PATH=NODE_PATH)
r = subprocess.run([NODE, '-e', check_js], cwd=ROOT, capture_output=True, text=True, env=env)
print(r.stdout.strip() or r.stderr.strip())
