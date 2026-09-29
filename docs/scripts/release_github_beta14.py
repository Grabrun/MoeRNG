# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.14 GitHub Release + 上传 zip（先推 main 再建 Release）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.14"
ZIP = "releases/MoeRNG-2.0.0-beta.14-20260929-234908.zip"
BODY = """## 前台文案优化（中文优先 + 一致性 + 渲染修复）

- 修复 docs 页渲染 bug：`**功能性要求**` markdown 星号残留 → `<strong>`
- 在线测试页中文化：按钮「Send Request」→「发送请求」；
  「Redirect (图片直出)」→「重定向（直接输出图片）」；结果提示同步
- 页脚中文化：「Open-source under MIT License」→「开源项目（MIT License）」
- 首页统计说明精简：「实时统计 · 图片与分类随后台更新」
- 关于页 GitHub 未配置提示改为面向访客的明确表述
- 回归：PHP syntax 全绿"""

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
