# -*- coding: utf-8 -*-
"""核验 v2.0.0-beta.1-10 远程 tag 指向 vs 本地 tag 指向，输出不一致清单"""
import io, json, os, subprocess, urllib.request

ROOT = r"E:\Projects\DouBao\MoeRNG"
with io.open(os.path.join(ROOT, '.dsh', 'moerng.token'), encoding='utf-8') as f:
    TOKEN = f.read().strip()

def api(url):
    req = urllib.request.Request(url, headers={"Authorization": "token " + TOKEN, "User-Agent": "moerng-check"})
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())

tags = api("https://api.github.com/repos/Grabrun/MoeRNG/tags?per_page=100")
remote = {}
for t in tags:
    if t['name'].startswith('v2.0.0-beta.'):
        # 注：tag 可能指向 tag 对象或 commit；此处取 commit sha
        remote[t['name']] = t['commit']['sha']

print('%-20s %-10s %-10s %s' % ('tag', '远程', '本地', '状态'))
for name in sorted(remote, key=lambda x: int(x.rsplit('.', 1)[1])):
    r = remote[name][:7]
    try:
        loc = subprocess.check_output(['git', 'rev-parse', name], cwd=ROOT, stderr=subprocess.DEVNULL).decode().strip()[:7]
    except subprocess.CalledProcessError:
        loc = 'N/A'
    status = 'OK' if r == loc else 'MISMATCH' if loc != 'N/A' else '本地缺失'
    print('%-20s %-10s %-10s %s' % (name, r, loc, status))
