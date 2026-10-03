# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.16 GitHub Release + 上传 zip（先推 main 再建 Release）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.16"
ZIP = "releases/MoeRNG-2.0.0-beta.16-20261003-175547.zip"
BODY = """## 前台各界面文案整体重写

- 首页：hero 副标题改为价值导向（调用一次/拿来即用/完全自控）；
  特性卡全部重写为顺畅叙述（无缓存无规律、子分类一起参与、低于 50ms 等）；
  抽图占位改为「点『试试手气』，随机取一张看看效果」
- 图库：引导语与空态重写（刷新页面换一批 / 去后台上传第一批）
- 关于页：简介改为定位式陈述（图片托管与随机接口一站搞定）；
  技术特性逐条打磨；开源寄语补充
- API 文档：统一全角标点与空格（分类标识（slug）、302 重定向、嵌套 JSON、
  sm（320）等），技术语义零改动
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
