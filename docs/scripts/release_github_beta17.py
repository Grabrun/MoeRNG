# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.17 GitHub Release + 上传 zip（先推 main 再建 Release）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.17"
ZIP = "releases/MoeRNG-2.0.0-beta.17-20261003-181408.zip"
BODY = """## 修复：删除图片时对象存储残留缩略图（软删除疑云根因）

- 此前 `Image::delete()` 只删除主文件 `path`，同一资产前缀下的三档缩略图
  （`thumb-sm/md/lg.webp`）残留在对象存储——删图后存储不释放，看起来像
  「软删除」。数据库侧始终是硬删除（DELETE 行），无软删除逻辑。
- 修复：删除时按 `thumbs` JSON（权威登记，`thumb_path` 列 = md 冗余）
  逐 key 删除全部缩略图，主文件与缩略图去空去重后一次性处理。
- 存储删除失败不再静默吞掉：DB 删除成功后若存储删除有失败项，写入审计
  `image_delete_storage_failed`（含 id 与失败路径清单），便于排查孤儿文件。
- 说明：历史已删除图片留下的孤儿缩略图无法自动清扫（`StorageInterface` 无
  LIST 能力），需到对象存储控制台按 `{yyyy}/{mm}/{uuid}/` 前缀人工清理。
- 验证：PHP syntax 全绿；删除覆盖逻辑三用例断言通过（3 档全删 / 仅 md
  去重 / 无缩略图仅主文件）。"""

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
