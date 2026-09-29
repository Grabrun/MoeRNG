# -*- coding: utf-8 -*-
"""对比 beta.8/9/10 的 created_at / published_at，确认网页排序"""
import io, json, os, urllib.request

ROOT = r"E:\Projects\DouBao\MoeRNG"
with io.open(os.path.join(ROOT, '.dsh', 'moerng.token'), encoding='utf-8') as f:
    TOKEN = f.read().strip()

def api(url):
    req = urllib.request.Request(url, headers={"Authorization": "token " + TOKEN, "User-Agent": "moerng-check"})
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())

rels = api("https://api.github.com/repos/Grabrun/MoeRNG/releases?per_page=5")
for r in rels:
    print('%-18s created_at=%s published_at=%s draft=%s assets=%d' % (
        r['tag_name'], r['created_at'], r.get('published_at'), r['draft'], len(r['assets'])))
