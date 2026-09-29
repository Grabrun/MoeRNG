# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.12 GitHub Release + 上传 zip（先推 main 再建 Release，tag 指向正确提交）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.12"
ZIP = "releases/MoeRNG-2.0.0-beta.12-20260929-225955.zip"
BODY = """## 处理队列触发策略重构（按用户诉求）

- **移除**「页面加载 1.5s 后自动消费处理队列」—— 后台访问图片页不再随时触发处理
- **保留两个触发时机**：① 上传批次完成后自动跑一次（一次清空全部 pending）；
  ② 用户手动触发（图片处理页「处理」按钮 / 图片管理页「重试失败项」）
- **并发防护**：processQueue 标记 processing 改为条件更新（AND process_status='pending'），
  并发请求（上传完成 + 手动同点）不再重复拾取同一批；被抢先时整批放弃、下次轮询自然拾取剩余
- **batch 3 → 10**：前端 BATCH 与服务端默认值（processQueue / backfillThumbs）同步放大，
  积压处理轮数减少约 2/3
- 历史积压不依赖页面访问：`php src/cli/backfill-thumbs.php` 一次补齐
- 回归：PHP syntax 全绿、php_undef 全绿、JS 语法（node --check）通过"""

with io.open(os.path.join(ROOT, '.dsh', 'moerng.token'), encoding='utf-8') as f:
    TOKEN = f.read().strip()

def api(url, data=None, headers=None, method=None, binary=None):
    h = {"Authorization": "token " + TOKEN, "User-Agent": "moerng-release"}
    if headers:
        h.update(headers)
    body = None
    if binary is not None:
        body = binary
    elif data is not None:
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(url, data=body, headers=h, method=method or ('POST' if body is not None else 'GET'))
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read()
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        raise SystemExit('[FAIL] HTTP %s %s\n%s' % (e.code, url, e.read().decode('utf-8', 'replace')[:400]))

rel = api("https://api.github.com/repos/%s/releases" % REPO,
          {"tag_name": TAG, "name": TAG, "body": BODY, "draft": False, "prerelease": True})
print('[OK] release 创建: %s' % rel['html_url'])
upload_url = rel['upload_url'].replace('{?name,label}', '?name=' + urllib.parse.quote(os.path.basename(ZIP)))
with open(os.path.join(ROOT, ZIP.replace('/', os.sep)), 'rb') as f:
    binary = f.read()
up = api(upload_url, headers={"Content-Type": "application/zip"}, method="POST", binary=binary)
print('[OK] 资产上传: %s (%d bytes)' % (up['name'], up['size']))
