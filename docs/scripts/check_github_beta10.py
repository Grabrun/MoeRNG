# -*- coding: utf-8 -*-
"""核实 GitHub 上 v2.0.0-beta.10 的 Release / tag / main HEAD 状态"""
import io, json, os, urllib.request, urllib.error

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"

with io.open(os.path.join(ROOT, '.dsh', 'moerng.token'), encoding='utf-8') as f:
    TOKEN = f.read().strip()

def api(url):
    req = urllib.request.Request(url, headers={"Authorization": "token " + TOKEN, "User-Agent": "moerng-check"})
    try:
        with urllib.request.urlopen(req) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        return {'error': e.code, 'body': e.read().decode('utf-8', 'replace')[:300]}

rels = api("https://api.github.com/repos/%s/releases?per_page=30" % REPO)
if isinstance(rels, list):
    names = [(r['tag_name'], r['name'], r['draft'], r['prerelease'], len(r.get('assets', [])),
              (r['assets'][0]['name'] if r.get('assets') else None)) for r in rels]
    print('=== Releases（最新在前）===')
    for n in names:
        print(n)
    b10 = [r for r in rels if r['tag_name'] == 'v2.0.0-beta.10']
    if b10:
        print('\n[v2.0.0-beta.10 release 存在] assets=%d html_url=%s' % (len(b10[0]['assets']), b10[0]['html_url']))
    else:
        print('\n[v2.0.0-beta.10 release 不存在]')
else:
    print('releases 查询失败:', rels)

tags = api("https://api.github.com/repos/%s/tags?per_page=30" % REPO)
if isinstance(tags, list):
    print('\n=== Tags ===')
    print([t['name'] for t in tags])
    print('[v2.0.0-beta.10 tag 存在]' if any(t['name'] == 'v2.0.0-beta.10' for t in tags) else '[v2.0.0-beta.10 tag 不存在]')
else:
    print('tags 查询失败:', tags)

head = api("https://api.github.com/repos/%s/commits/main" % REPO)
if isinstance(head, dict) and 'sha' in head:
    print('\nmain HEAD =', head['sha'][:7], head['commit']['message'].split('\n')[0][:60])
else:
    print('main 查询失败:', head)
