# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.13 GitHub Release + 上传 zip（先推 main 再建 Release）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.13"
ZIP = "releases/MoeRNG-2.0.0-beta.13-20260929-231413.zip"
BODY = """## 图片处理页按钮合并：「重试失败项」

- 合并原「重试全部失败」+「重试元数据补全」两个按钮为单一「重试失败项」
- 一键依次完成三步：① 主图失败项（process_status='failed'）重新入队；
  ② 处理队列（含刚入队的失败项，进度条 + 统计联动）；
  ③ 缩略图元数据失败项（partial/failed）定向重试
- 快路径：两者皆空时提示「没有需要重试的失败项」，不再空跑请求
- 按钮禁用逻辑合并：主图失败与元数据失败皆无时才置灰
- 删除 queue-retry-meta 按钮（HTML + JS 事件），runThumbMetaRetry 函数保留复用；
  提示条措辞同步更新
- 回归：JS 语法（node --check）、PHP syntax 全绿"""

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
