# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.9 GitHub Release + 上传 zip（GitHub REST API）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.9"
ZIP = "releases/MoeRNG-2.0.0-beta.9-20260929-192007.zip"
BODY = """## 前端性能优化（不影响功能）

- **P1 零感知层**：字体 preconnect ×2（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com）与 dns-prefetch，字体两跳 TTFB 提前；.htaccess 静态资源长缓存（public, max-age=31536000, immutable，ASSET_VER 指纹安全，Apache 部署对齐 nginx）
- **P2 渲染层**：字体 CSS 异步化（media=print + CSP nonce 合规脚本立即切回 all，display=swap 兜底无空白），首屏不再等待跨域字体样式表
- **P3 结构性**：前台 JS 拆分 front.js（24.5KB 子集：toast/copyText/API 测试/随机图预览/reveal/统计滚动/动态样式，逐字节源自 app.js 切片），前台 JS 传输 157KB→56KB（gzip 后约省 30KB+）；后台 app.js 保持原样零回归
- **维护**：layout_contract 版本断言语义化（v2 beta 线）；新增 docs/scripts/verify_front_runtime.js（front.js 前台 DOM 运行时等价验证）与 patch_m6_perf.py（可复现变更脚本）
- **回归**：syntax 2912/2912、design_contract 63、api_contract 67、hero_stats 36、perf_contract 74、layout_contract 125、page/click/xref/ref 全绿、front.js 运行时链零异常"""

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
