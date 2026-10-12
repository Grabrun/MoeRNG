# -*- coding: utf-8 -*-
"""发布 v2.0.0-beta.18 GitHub Release + 上传 zip 与 zip.sha256（先推 main 再建 Release）"""
import io, json, os, urllib.request, urllib.error, urllib.parse

ROOT = r"E:\Projects\DouBao\MoeRNG"
REPO = "Grabrun/MoeRNG"
TAG = "v2.0.0-beta.18"
ZIP = "releases/MoeRNG-2.0.0-beta.18-20261012-125155.zip"
SHA = ZIP + ".sha256"
BODY = """## 深度审计 P1 修复（8 条 High + 关联 Medium）

- MR-01 会话硬化：CspNonce 三处裸 `session_start()` 统一改走 `Session::start()`，
  bootstrap 增加统一硬化会话入口，PHPSESSID 恒带 HttpOnly/SameSite=Lax/Secure。
- MR-02 角色守卫：新增 `RequireAdminMiddleware`，用户管理/API Key/存储配置/站点保存/
  图片删除等 23 条高危路由强制 admin；editor 仅保留上传/分类/浏览/队列操作。
- MR-03 API Key 哈希化：落库改为 SHA-256 哈希+前缀，明文仅生成时返回一次；
  新增 `src/cli/migrate-apikey-hash.php` 存量 Key 重发工具（默认 dry-run、幂等）。
  **升级部署后旧 Key 立即失效，必须运行迁移工具并重新分发新 Key。**
- MR-23 API Key 管理页不再嵌入完整明文（移除 data-key），「仅展示一次」承诺成立。
- MR-04 又拍云签名按官方规范重写（`_upt`=md5 中段 8 位+etime，路径参与签名），
  新增独立 `token_key` 防盗链密钥字段并纳入密封；未配置时不签名返回裸 URL。
- MR-05 孤儿对象回收：上传失败 catch 分支回滚已上云 key 并审计
  （`image_rollback_failed`）；queueClear 先逐行回收对象再删库，`orphan_risk`
  改为实际残留计数。
- MR-06 COS SDK 钉 tag：受环境限制（无 composer）未完成，详见
  `docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md`。
- MR-07 发布链：打包/发布脚本迁入版本控制 `scripts/`（令牌改环境变量、
  确定性选包），发布时产出 `.sha256` 校验和资产（本 Release 已附）。
- MR-08 CI：新增 `.github/workflows/ci.yml`（PHP 语法门禁 + node harness 门禁 +
  依赖审计预留）+ `.github/dependabot.yml`。
- MR-12 存储凭据回显脱敏：控制器响应与列表 `data-config` 对 secret/key/token_key
  掩码/过滤，编辑留空=保留原值。
- MR-36 密封裸 AccessKeyId：`CredentialCipher::isSecretField` 增补 key/token_key
  精确匹配，下次保存 profile 时自动密封。

验证：PHP 语法 2916/2916 全绿；未定义变量检查 58 文件零命中；五个 MR 复刻断言
脚本全部通过；运行时行为（真实 DB/对象存储/签名 URL）待部署环境验证。"""

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
for asset, ctype in ((ZIP, "application/zip"), (SHA, "text/plain")):
    upload_url = rel['upload_url'].replace('{?name,label}', '?name=' + urllib.parse.quote(os.path.basename(asset)))
    with open(os.path.join(ROOT, asset.replace('/', os.sep)), 'rb') as f:
        binary = f.read()
    up = api(upload_url, headers={"Content-Type": ctype}, method="POST", binary=binary)
    print('[OK] 资产上传: %s (%d bytes)' % (up['name'], up['size']))
