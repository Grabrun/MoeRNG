# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.18 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.17')"
new = "define('APP_VERSION', '2.0.0-beta.18')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION -> 2.0.0-beta.18')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.18] - 2026-10-12\n"
    "\n"
    "### 安全加固：深度审计 P1 修复\n"
    "\n"
    "- MR-01 会话硬化：CspNonce 三处裸 session_start() 统一改走 Session::start()，\n"
    "  bootstrap 增加统一硬化会话入口，PHPSESSID 恒带 HttpOnly/SameSite=Lax/Secure。\n"
    "- MR-02 角色守卫：新增 RequireAdminMiddleware，用户管理/API Key/存储配置/站点保存/\n"
    "  图片删除等 23 条高危路由强制 admin；editor 仅保留上传/分类/浏览/队列操作。\n"
    "- MR-03 API Key 哈希化：落库改为 SHA-256 哈希 + 前缀，明文仅生成时返回一次；\n"
    "  新增 src/cli/migrate-apikey-hash.php 存量 Key 重发工具（默认 dry-run，幂等）。\n"
    "- MR-23 API Key 管理页不再嵌入完整明文（移除 data-key），「仅展示一次」承诺成立。\n"
    "- MR-04 又拍云签名按官方规范重写（_upt=md5 中段 8 位+etime，路径参与签名），\n"
    "  新增独立 token_key 防盗链密钥字段并纳入密封；未配置时不签名返回裸 URL。\n"
    "- MR-05 孤儿对象回收：上传失败 catch 分支回滚已上云 key 并审计\n"
    "  （image_rollback_failed）；queueClear 先逐行回收对象再删库，orphan_risk 改实际残留计数。\n"
    "- MR-06 COS SDK 钉 tag：受环境限制（本机无 composer）未完成，\n"
    "  见 docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md。\n"
    "- MR-07 发布链：打包/发布脚本迁入版本控制 scripts/（令牌改环境变量、确定性选包），\n"
    "  发布时产出 .sha256 校验和资产。\n"
    "- MR-08 CI：新增 .github/workflows/ci.yml（PHP 语法门禁 + node harness 门禁 +\n"
    "  依赖审计预留）+ .github/dependabot.yml。\n"
    "- MR-12 存储凭据回显脱敏：控制器响应与列表 data-config 对 secret/key/token_key\n"
    "  掩码/过滤，编辑留空=保留原值。\n"
    "- MR-36 密封裸 AccessKeyId：CredentialCipher::isSecretField 增补 key/token_key\n"
    "  精确匹配，下次保存 profile 时自动密封。\n"
    "- 验证：PHP 语法 2916/2916 全绿、未定义变量检查 58 文件零命中、\n"
    "  五个 MR 复刻断言脚本全部通过；运行时行为（真实 DB/对象存储/签名 URL）待部署环境验证。\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.17] - 2026-10-03"
if '2.0.0-beta.18' in c:
    print('[SKIP] CHANGELOG 已含 beta.18')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.18 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
