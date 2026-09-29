# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.10 GitHub Release + 上传 zip"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.10"
ZIP = "releases/MoeRNG-2.0.0-beta.10-20260929-215127.zip"
BODY = """## 修复：首页白屏（hotfix）

- **根因**：beta.9 在视图（front_header.php / admin/layout.php）中引入 `<script<?= CspNonce::attr() ?>>`，
  未限定名调用依赖「视图在 App\\Core 命名空间上下文被 Controller::render() include」的隐式约定；
  若任一渲染路径不在该上下文（install 旁路等），解析为 \\CspNonce 触发 PHP Fatal Error（Class not found）
  → 输出缓冲被丢弃 → 整页白屏，banner 未渲染（preload not used 伴随警告）
- **修复**：视图内改用全限定名 `\\App\\Core\\CspNonce::attr()`（不依赖命名空间继承，任何上下文均正确解析）；
  字体切换脚本 try/catch 静默降级
- **伴随**：CSP `connect-src` 放行字体两跳域（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com），
  消除 preconnect 被 `connect-src 'self'` 拦截的控制台报错
- 回归：全量 harness 全绿（syntax/design 63/api 67/hero 36/perf 74/layout 125/矩阵/scope/click/front 运行时）"""

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

path = os.path.join(ROOT, ZIP.replace('/', os.sep))
rel = api("https://api.github.com/repos/%s/releases" % REPO,
          {"tag_name": TAG, "name": TAG, "body": BODY, "draft": False, "prerelease": True})
print('[OK] release 创建: %s' % rel['html_url'])
upload_url = rel['upload_url'].replace('{?name,label}', '?name=' + urllib.parse.quote(os.path.basename(path)))
with open(path, 'rb') as f:
    binary = f.read()
up = api(upload_url, headers={"Content-Type": "application/zip"}, method="POST", binary=binary)
print('[OK] 资产上传: %s (%d bytes)' % (up['name'], up['size']))
