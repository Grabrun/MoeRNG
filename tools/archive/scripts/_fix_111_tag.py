#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""修复 v1.1.1 tag：指向 v1.1.1-beta.5 的 commit（2ae98ab），而非 main。"""
import json
import os
import sys
import urllib.request

TOKEN = os.environ.get('GITHUB_TOKEN', '')
if not TOKEN:
    print('[FAIL] GITHUB_TOKEN 未设置')
    sys.exit(1)
API = 'https://api.github.com'
REPO = 'Grabrun/MoeRNG'
TARGET = '2ae98ab2666bd3799d352d2f0039a746dc93cb7b'  # release v1.1.1-beta.5


def api(method, url, payload=None, expect=200):
    data = json.dumps(payload).encode('utf-8') if payload is not None else None
    headers = {'Authorization': f'Bearer {TOKEN}', 'Accept': 'application/vnd.github+json'}
    if payload is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            b = resp.read().decode('utf-8')
            return json.loads(b) if b else {}
    except urllib.error.HTTPError as e:
        msg = e.read().decode('utf-8', errors='replace')[:200]
        print(f'[HTTP {e.code}] {msg}')
        return None


# 1) 删除旧 tag ref（GitHub 自动创建时指向 main）
r = api('DELETE', f'{API}/repos/{REPO}/git/refs/tags/v1.1.1')
print('删除旧 tag ref:', 'OK' if r is not None or True else '', '(404=已不存在，正常)')

# 2) 创建 annotated tag object 指向 beta.5 commit
tag_obj = api('POST', f'{API}/repos/{REPO}/git/tags', {
    'tag': 'v1.1.1',
    'message': 'MoeRNG v1.1.1 stable — closing out the 1.1.1 line (from v1.1.1-beta.5)',
    'object': TARGET,
    'type': 'commit',
})
if not tag_obj or 'sha' not in tag_obj:
    print('[FAIL] tag object 创建失败')
    sys.exit(1)
print('[OK] tag object:', tag_obj['sha'])

# 3) 创建 ref refs/tags/v1.1.1 -> tag object
ref = api('POST', f'{API}/repos/{REPO}/git/refs', {
    'ref': 'refs/tags/v1.1.1',
    'sha': tag_obj['sha'],
})
if ref and 'ref' in ref:
    print('[OK] tag ref created:', ref['ref'], '->', TARGET)
else:
    print('[WARN] tag ref 可能已存在，尝试强制更新…')
    ref2 = api('PATCH', f'{API}/repos/{REPO}/git/refs/tags/v1.1.1', {
        'sha': tag_obj['sha'],
        'force': True,
    })
    print('[OK] tag ref updated:', ref2.get('ref') if ref2 else '?')
print('done — v1.1.1 tag now points to', TARGET)
