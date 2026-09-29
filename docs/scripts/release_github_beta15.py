# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.15 GitHub Release + 上传 zip（先推 main 再建 Release）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.15"
ZIP = "releases/MoeRNG-2.0.0-beta.15-20260929-235308.zip"
BODY = """## 前台交互文案中文化（front.js 全面审计）

- **修复按钮文字不一致**：在线测试按钮 HTML 已为「发送请求」，但 JS 运行时
  点击后重置为英文「Send Request」——统一为「发送请求」
- 加载态「Loading...」→「加载中…」
- 状态徽标：OK→成功、Failed→失败、Network Error→网络错误、Error→错误
  （HTTP 标准 statusText 如 404 Not Found 保留不动）
- 测试历史记录文案全角化：「302 → 图片（200 成功，X）」；「错误：X」
- 随机图预览 alt「Random Image」→「随机图片」；meta 冒号全角化「分类：X」
- 回归：JS 语法（node --check）通过，英文残留复查为 0"""

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
