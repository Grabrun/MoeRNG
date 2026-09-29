# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.11 GitHub Release + 上传 zip（先推 main 再建 Release，tag 指向正确提交）"""
import io, json, os, subprocess, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.11"
ZIP = "releases/MoeRNG-2.0.0-beta.11-20260929-223933.zip"
BODY = """## 新增：前台图库缩略图一键补全（CLI 工具）

- **背景**：前台 /gallery 对「缩略图缺失」的图片回退加载原图 —— 275×206 的显示空间
  加载数 MB 原图，带宽浪费严重（用户反馈「使用了原图而不是合适的缩略图」）
- **根因**：缩略图为异步队列生成（processing_state.thumb_meta 停在 pending）；
  历史图（v2 迁移前）与从未触发队列的图没有缩略图 → displayUrl('sm') 回退链
  （sm → md → 原图）到底 → 前台 /gallery 加载原图。前台代码本身已正确使用缩略图
  （displayUrl('sm') + srcset sm,md + sizes 240px），无需改动
- **交付**：`src/cli/backfill-thumbs.php`（父循环）+ `src/cli/backfill-thumbs-batch.php`
  （单批子进程）—— 构造带合法 CSRF 的 Request，走 ImageController::backfillThumbs()
  同一份生成/上传/落库逻辑（零分叉），部署后执行一次即补齐全部历史缩略图，
  前台 /gallery 自动改用 sm 320w 缩略图（1.16x 超采样，带宽 -90%+）
- **用法**：`php src/cli/backfill-thumbs.php [--batch 10] [--retry]`；
  亦可使用后台「图片管理 → 补全历史缩略图」按钮（同一逻辑）
- 回归：syntax 全绿、php_undef 全绿"""

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
