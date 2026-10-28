# MoeRNG 项目交接文档（Agent Hand-off）

> **重写时间**：2026-10-12（第二版，按 beta.18 发版后 + P2 中断的真实状态重写）
> **撰写时版本**：v2.0.0-beta.18（`src/bootstrap.php:33` APP_VERSION，HEAD `2620811`，已发 GitHub Release）
> **文档性质**：给下一个接手 Agent 的操作手册。长期心智模型另见 `docs/MAINTAINER-GUIDE.md`（滞后 beta.1，见 MR-72）与 `.dsh/memory/MEMORY.md`（本地，不入库）。
> **阅读顺序**：先读第 0 章并逐条执行，再按需查其余章节。所有状态均经命令实查（git/GitHub API/harness），冲突时以实际代码为准并回写本文。

---

## 0. 接手第一步（TL;DR）

1. **没有正在运行的修复任务**：原组织代理 `o_000E5baRs2S`（P1→P3 全量修复）**已被取消（cancelled，停在 P2 的 62%）**。P1 已完整发版；**P2 半成品改动正留在工作区未提交**（24 个 modified + `.github/workflows/` 未跟踪）。不要重新委派已完成部分，按第 3 章盘点表接续。
2. **核对工作区**：`git status`、`git log --oneline -5`、`git diff --stat`。当前 HEAD `2620811`（beta.18），工作区是 P2 半成品（harness 实测全绿，见第 3.4 节），**不是脏工作区，不要丢弃**。
3. **立即补一个 P1 遗漏**：`.github/workflows/ci.yml` 已写好但**从未 git add**（beta.18 提交漏掉了它，GitHub 上 CI 至今未生效）。确认内容无误后随 P2 一起提交，或单独补一个 commit。
4. **读审计报告**：`docs/audit/MoeRNG-深度审计报告-2026-10-11.md`（72 条发现 MR-01~MR-72，第 3 章总览、第 4 章证据+修复建议、第 6 章路线图）。
5. **本机环境限制**：**无 PHP CLI、无 MySQL、无 composer、无真实对象存储**。验证手段只有：node harness（PHP 语法/AST）、JS 语法、node 逻辑复刻断言、调用链静态追踪。运行时行为一律标「未验证，需 PHP+MySQL/线上复测」。

---

## 1. 项目身份

| 项 | 值 |
|---|---|
| 定位 | 随机二次元图片 API（JSON / 302 redirect）+ 自托管萌系图床 + 管理后台 |
| 线上 | https://images.grabrun.top（宝塔 Nginx + PHP-FPM） |
| 仓库 | https://github.com/Grabrun/MoeRNG（public，main，身份 Grabrun） |
| 本地路径 | `E:\Projects\DouBao\MoeRNG` |
| 技术栈 | PHP 8.4 + MySQL；PSR-4 自研轻量框架（**无 Composer 运行时**）；前端原生 JS（无构建） |
| 存储 | 8 驱动：Local / COS / OSS / S3 兼容 / OBS / 七牛 / 又拍云 / AWS（`src/app/Storage/` + `src/sdk/`） |
| doc-root | **项目根（非 public/）**；入口 `src/index.php`、`api.php`、`admin.php`、`install.php`、`doctor.php` |
| 当前版本 | **v2.0.0-beta.18 已发布**；下一批 P2 收尾后 bump beta.19，P3 后 beta.20 |
| 稳定版 | v1.4.0（历史 tag）；v1.5 系列从未发版（能力并入 beta 线，见 CHANGELOG 谱系说明） |

### 请求链

```
浏览器/API 客户端
  ├─ /          (index.php)  → HomeController：首页/图库/API文档/tester/关于
  ├─ /api/v1/*  (api.php)    → ApiKeyAuth → RateLimit → ApiController（random/list/categories/stats）
  ├─ /admin/*   (admin.php)  → AuthMiddleware → RequireAdmin(23 条高危路由) → Csrf → Admin\Controllers
  ├─ /files     (FileController) → SignedUrl HMAC → 多根回退 → 流式输出（本地媒体）
  ├─ install.php → 安装向导（installed 锁；覆盖安装不重显，beta.7）
  └─ doctor.php  → 诊断端点（限管理员会话，部署后建议删，MR-70）
```

---

## 2. 版本与发布状态（已实查）

| 项 | 状态 |
|---|---|
| beta.18 commit | `2620811` `fix(audit): P1 深度审计修复（beta.18）`，33 文件 +3799/-92 |
| 本地 tag `v2.0.0-beta.18` | SHA `2620811fe63a4ed0214e6c7fe5c086b4c0ae3ad1` |
| 远程 tag（GitHub API 实查） | SHA `2620811…e3ad1` —— **本地 tag == 远程 tag == HEAD，三者一致 ✓** |
| GitHub Release | 已发布：prerelease=true，draft=false，published 2026-10-12T04:55:16Z |
| Release 资产 | `MoeRNG-2.0.0-beta.18-20261012-125155.zip`（2,761,965 B）+ 同名 `.sha256`（108 B）✓ |
| 本地归档 | `releases\MoeRNG-2.0.0-beta.18-20261012-125155.zip` + `.sha256` ✓ |
| **CI 遗漏** | `.github/workflows/ci.yml` 存在于工作区但**未被 git 跟踪/提交**（`.github/dependabot.yml` 已入库）；beta.18 的 zip 与 GitHub 均无 CI，**接手后必须补提交** |

---

## 3. 审计修复进度总览（核心章节）

审计产物：`docs/audit/MoeRNG-深度审计报告-2026-10-11.md`（72 条：8 High / 22 Medium / 21 Low / 21 Info）；风险分布 HTML 同目录。8 High + 抽查 6 Medium 已经独立复核全部属实（唯一行号偏差：MR-07 `.gitignore:36` 实为 `:35`）。

### 3.1 P1（beta.18）——✅ 已发布

| ID | 内容 | 落地位置（beta.18） |
|---|---|---|
| MR-01 | 会话硬化 | `CspNonce.php` 三处裸 session_start→`Session::start()`；`Application.php` bootstrap 统一入口；mr01_session_test.js ✓ |
| MR-02 | 角色强制 | 新增 `Middleware/RequireAdminMiddleware.php`；`admin.php` 23 条高危路由；`UserController` role 白名单；mr02_role_guard_test.js ✓ |
| MR-03 | API Key 哈希化 | `schema.sql` key_hash CHAR(64)+key_prefix；`ApiKey.php` SHA2 反查；明文仅生成时一次；迁移 CLI `src/cli/migrate-apikey-hash.php`（dry-run 默认、幂等）；mr03 测试 ✓ |
| MR-23 | Key 列表脱敏 | 视图去 data-key、app.js 去复制明文路径 |
| MR-04 | 又拍云官方签名 | `UpyunSdkDriver::url()` 改 `_upt=substr(md5(token_key&etime&path),12,8).etime`；新增 token_key 字段并密封；未配置返裸 URL；mr04 测试 ✓ |
| MR-05 | 孤儿对象回收 | 上传 catch 回滚（image_rollback_failed 审计）+ queueClear 先回收再删库；mr05_orphan_test.js 21 断言 ✓ |
| MR-07 | 发布链入库+校验和 | 新增入库 `scripts/release.py`、`scripts/_package.py`（环境变量取 token、确定性选包、出 .sha256） |
| MR-08 | CI 门禁 | `.github/dependabot.yml` 已入库；**`workflows/ci.yml` 漏提交（见第 2 节，待补）**；harness 入库 `scripts/harness/`（syntax_check/php_undef_check/xref_audit） |
| MR-12 | 凭据回显脱敏 | `StorageProfileController` toClientItem() 掩码；update keepSecrets「留空=不改」；视图 data-config 过滤 |
| MR-36 | AccessKeyId 密封 | `CredentialCipher::isSecretField()` 扩名单 |
| MR-06 | COS SDK dev-master | **未修（受限）**：本机无 composer，说明见 `docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md` |

**beta.18 上线后必须执行/复测（交用户）**：
- 在 PHP 环境跑 `php src/cli/migrate-apikey-hash.php`（先 dry-run）；**apply 后存量 API Key 全部重发、旧 key 失效**，新明文仅写入迁移报告一次。
- 复测：curl -v 看 Set-Cookie 属性；editor 账号高危动作应 403；又拍云开防盗链后签名 URL 可访问；上传失败/清队列的云端回收。

### 3.2 P2（beta.19）——⏸ 半成品在工作区（代理被取消，harness 实测全绿）

工作区 24 个 modified（+812/-114）+ `.github/workflows/` 未跟踪。**实测：syntax_check 2916/2916 OK；php_undef_check 58 文件 0 未定义；4 个 P2 复刻测试脚本全部通过**。下表为逐 MR 实查盘点：

| ID | 状态 | 证据 / 位置 | 收尾动作 |
|---|---|---|---|
| MR-09 限流 TOCTOU | ✅ 代码+测试 | `schema.sql:131` UNIQUE `uq_ident_window(identifier,window_key)`；RateLimitMiddleware 原子 upsert；p2_rate_limit 31 断言过 | 随 beta.19 提交；上线验证真实并发 |
| MR-10 文件限流非原子 | ✅ 代码+测试 | `RateLimiter.php:56/78` 全程 LOCK_EX（c+ 打开）；31 断言过 | 同上 |
| MR-11 密钥降级明文 | ✅ 代码 | `CredentialCipher.php:99-125` fail-closed，抛 `CredentialCipherKeyUnavailable`，不再静默明文 | 确认所有 sealConfig 调用方 catch 了新异常 |
| MR-14 logo 白名单+.htaccess | ✅ 代码 | `SettingController.php` +10；`src/.htaccess` +14 | 复核白名单扩展名与 .htaccess 块语法 |
| MR-15 队列复位削弱认领 | ✅ 代码+测试 | ImageController；p2_image_controller 32 项（A1-D1）过 | — |
| MR-16 Category 递归 | ✅ 代码 | `Category.php` +56（深度闸/事务） | 复核事务边界 |
| MR-17 profile_id 无 FK | ✅ 代码 | `StorageProfile.php` +6、Controller +34（删实例引用计数检查） | 确认计数缺失时拒绝删除的返回提示 |
| MR-18 LocalDriver `..` | ✅ 代码+测试 | `LocalDriver.php:145-146` 拒绝 `..` 段 + realpath 包含；p2_storage 过 | — |
| MR-20 云驱动超时 | ⚠️ 代码待复核 | `QiniuSdkDriver.php:55-58` 有超时加固注释与改动（+21 行），**测试未单列**；Aws 等驱动需确认 | 读 curl 配置确认 TIMEOUT/CONNECTTIMEOUT 实际传参；补断言 |
| MR-21 S3 TLS verify | ✅ 代码+测试 | `S3Driver.php:531` 默认 verify=true；`:535` false 仅「显式自托管自签网关」分支（注释 deliberate chosen）；p2_storage 过 | — |
| MR-28 signing_key chmod | ✅ 代码 | `SignedUrl.php:57` `@chmod($keyFile,0640)`（Windows no-op 注释在） | 上线 Unix 验证权限位 |
| MR-31 Host 头注入 | ✅ 代码+测试 | `Application.php:571/588 sanitizeHost`；base_url 优先；CRLF/空 host 回退；31 断言过 | — |
| MR-32 每请求探测/备份阻塞 | ✅ 代码+测试 | `RateLimitMiddleware.php:114-121` 静态缓存 `$rateLimitTableReady`；31 断言过；备份锁注释 :654 | 备份是否异步化需再确认（可能只做了探测缓存） |
| MR-33 random 并发空结果 | ✅ 代码+测试 | `Image.php:518-530` OFFSET 空→无偏移 LIMIT 1 兜底重查；p2_image_model 17 断言过 | — |
| MR-34 randomBatch 重复 | ✅ 代码+测试 | `Image.php:587-600` 补齐 `AND id NOT IN(...)` 排除已取；17 断言过 | — |
| MR-35 分类 N+1 | ✅ 代码+测试 | 一次取树+BFS+环守卫+请求缓存；17 断言过 | — |
| MR-37 backfill 并发认领 | ✅ 代码+测试 | flock 认领 + waiting 响应 + 降级；p2_image_controller（E1-E7）过 | — |
| MR-38 审计留痕 | ⚠️ 基本完成待对表 | 各控制器 AuditLog::record 计数：Image 6 / Setting 6 / StorageProfile 5 / Auth 5 / ApiKey 4 / User 4 / Category 3；Image 侧 32 项测试过 | 按报告第 4 章 MR-38 要求逐动作对表（删图/改用户/改凭据/清队列/存储变更），补缺；audit_logs 保留策略未做 |
| MR-39 endpoint SSRF | ✅ 代码+测试 | `S3Driver.php:96-126` 管理面 endpoint/sourceDomain 内网守卫（error_log 记录）；p2_storage 过 | 其余驱动是否共用同一守卫需确认 |
| MR-40 delete 语义不一致 | ✅ 代码+测试 | LocalDriver 幂等对齐；p2_storage 过 | — |
| MR-41 签名 URL 时间窗 | ✅ 代码+测试 | `SignedUrl.php` +27、Qiniu 改动；窗口对齐；p2_storage 过 | — |
| MR-42 预签名失败降级裸 URL | ⚠️ 待复核 | Cos/Oss/Obs `url()` catch 分支有改动（如 CosSdkDriver:197），**但是否停止静默降级裸 URL 未确认，测试未覆盖** | 读 4 个驱动 url() catch 体；应 fail-closed 或显式配置才允许裸 URL；补断言 |
| MR-43 downloadUrl 无上限 | ✅ 代码+测试 | `S3Driver.php:470 MAX_DOWNLOAD_BYTES=200MB`、:511 超限拒绝、:520 分块写；p2_storage 过 | — |

**P2 确认未开始（相关文件零改动）**：

| ID | 内容 | 说明 |
|---|---|---|
| MR-13 | `/api/v1/images` 补 `process_status='done'` | `ApiController.php` 未改，0.25 人日，最简单，优先补 |
| MR-19 | /files SVG 内联 disposition/nosniff | `FileController.php` 未改 |
| MR-22 | tester innerHTML DOM XSS | `front.js` 未改（注意 `app.js` 有副本 :289）；转义 API 响应 |
| MR-24 | 6 家 SDK 共用 Guzzle 树 | 供应链，需梳理 vendor |
| MR-25 | git add -A 风险 | P1 的 scripts/release.py 已缓解 token 面；.gitignore 加固/显式 add 仍待做 |
| MR-26 | PAT 轮换纪律 | P1 已改环境变量；轮换纪律文档待写 |
| MR-27 | Release draft 复核门 | `scripts/release.py` 未改 draft:true |
| MR-29 | nginx 例配 deny backups/var | `src/nginx.conf.example` 未改（**高优先，0.25 人日，防备份包被下载**） |
| MR-30 | 隐私/数据保留/合规文档 | 纯文档，未做 |
| — | audit_logs 保留策略 | MR-38 的后半（定时清理/轮转）未做 |

### 3.3 P3（beta.20）——未开始

MR-44 flash 属性清理、MR-45 uploads/.htaccess（-Indexes/nginx 说明，部分可能被 P2 的 src/.htaccess 改动覆盖，需核对）、MR-46 OBS monolog 未 vendor、MR-47 release_github.py 漏 import urllib.parse、MR-48 历史 strip_/patch_ 脚本硬编码路径（归档说明）、MR-49 密钥轮换/备份策略文档、MR-50 install 写配置 chmod、MR-51 README 配置模板漂移、MR-52 CSP nonce 重复生成+base-uri、MR-53 删 ApiAuthMiddleware 死代码、MR-54 AuditLog 写失败告警、MR-55 bootstrap 失败页脱敏、MR-57 status ENUM 死值、MR-58 迁移顺序死块、MR-59 testConnection 上传 __FILE__、MR-60 CosSdkDriver 注释漂移、MR-62 死视图 layout.php、MR-63/64/67 转义一致性、MR-65 helpers.js 主题块重复×4+toast 恒 alert、MR-66 users flash 双显、MR-68 SDK SBOM、MR-70 doctor.php 删除提示、MR-72 更新 MAINTAINER-GUIDE。MR-56（install CSRF）设计豁免记录即可；MR-61/69 为正面项。

### 3.4 P2 半成品验证命令（接手后先重跑确认）

```powershell
node .dsh\syntax_check.js                      # 实测 2916/2916 OK
node .dsh\php_undef_check.js                  # 实测 58 文件 0 未定义
node .dsh\p2_rate_limit_concurrency_test.js   # 实测 31 断言过（MR-09/10/31/32）
node .dsh\p2_image_model_test.js              # 实测 17 断言过（MR-33/34/35/38）
node .dsh\p2_image_controller_test.js         # 实测 32 项过（MR-15/37/38）
node .dsh\p2_storage_test.js                  # 实测 MR-18/21/39/40/41/43 过
# P1 测试：mr01_session_test / mr02_role_guard_test / mr03_apikey_hash_test / mr04_storage_cred_test / mr05_orphan_test
```

注意：`.dsh/` 不入库；P2 四个 p2_*.js 目前**只在 .dsh/**，beta.19 提交时若要让 CI 跑到，需复制进 `scripts/harness/`（P1 的五个 mr0*.js 同样未入库，按需一并迁入）。

---

## 4. 代码地图（beta.18 后）

```
MoeRNG/
├─ src/
│  ├─ bootstrap.php          # APP_VERSION 唯一定义（:33，当前 2.0.0-beta.18）+ 自动加载 + 装配
│  ├─ schema.sql             # DB schema 权威；P1 加 key_hash/key_prefix；P2 加 uq_ident_window
│  ├─ index.php/api.php/admin.php/install.php/doctor.php
│  ├─ nginx.conf.example     # MR-29 待补 deny backups/var
│  ├─ app/
│  │  ├─ Core/               # Application（P1 统一会话入口；P2 sanitizeHost/探测缓存）, Router,
│  │  │                      #   Database, Session, Request/Response, Model, Config, CspNonce,
│  │  │                      #   CredentialCipher（P2 fail-closed）, RateLimiter（P2 LOCK_EX）,
│  │  │                      #   ApiKeyAuthMiddleware, ApiAuthMiddleware(死代码 MR-53),
│  │  │                      #   RequireAdminMiddleware（P1 新增，23 路由）
│  │  ├─ Controllers/Admin/  # Auth/Dashboard/Image/Category/User/ApiKey/StorageProfile/Setting
│  │  │                      #   （P2 各控制器补 AuditLog::record）
│  │  ├─ Models/             # User, Image（P2 random 防御/树缓存）, Category（P2 深度闸）,
│  │  │                      #   ApiKey（P1 哈希）, StorageProfile（P2 引用计数）, Setting, AuditLog
│  │  └─ Storage/            # StorageInterface + Local（P2 ..防护/幂等）+ 6 云驱动 + S3Driver
│  │                         #   （P2 TLS/SSRF/下载上限）+ SignedUrl（P2 chmod/时间窗）
│  ├─ cli/                   # backfill-thumbs*.php；migrate-apikey-hash.php（P1，dry-run/--apply）
│  ├─ public/js/             # front.js（前台，MR-22 待修）、app.js（后台）、helpers.js（MR-65）
│  ├─ views/                 # admin/、install/、partials/（h() 转义纪律）
│  ├─ sdk/                   # 6 家官方 SDK（.gitignore 忽略，打包注入；COS 钉 dev-master 待解 MR-06/24/68）
│  ├─ config/                # .htaccess 入库；app/database/signing_key/credentials.php 不入库
│  └─ storage/               # uploads/（web 根外）、incoming/（暂存）；var/（限流计数）
├─ scripts/                  # 【已入库】release.py + _package.py + harness/（CI 用）
├─ .github/                  # dependabot.yml 已入库；workflows/ci.yml 待补提交（MR-08 收尾）
├─ docs/
│  ├─ audit/                 # 主报告 + 风险 HTML + MR-06 note + 历史审计 4 份
│  ├─ decisions/             # ADR-001-api-thumbnail-size.md、OPEN-DECISIONS.md
│  ├─ scripts/               # beta.9~18 的 patch_*/build_release/release_github_beta*.py（已入库）
│  ├─ DEV-PLAN.md、MAINTAINER-GUIDE.md（滞后 beta.1，MR-72）、PLAN-CANCEL-COMPAT-2026-09-28.md
│  └─ HANDOFF.md             # 本文
├─ tools/                    # 旧发布脚本（.gitignore 忽略，已被 scripts/ 取代，勿再用）
├─ .dsh/                     # 本地（.gitignore 忽略）：moerng.token、memory/、全部验证 harness
├─ releases/                 # 版本 zip + sha256 归档（.gitignore 忽略），最新 beta.18
└─ CHANGELOG.md / README.md / LICENSE / .gitattributes / .gitignore
```

---

## 5. 历史迭代脉络（既定事实，勿走回头路）

| 版本 | 主题 |
|---|---|
| beta.9 | 前端性能优化（preconnect/字体异步/.htaccess 缓存/front.js 拆分） |
| beta.10 | 首页白屏 hotfix（视图 CspNonce 全限定名） |
| beta.11 | 前台图库缩略图一键补全 CLI |
| beta.12 | **队列触发策略：仅「上传完成」+「图片处理页手动按钮」触发，不随时自动处理**（勿改回） |
| beta.13 | 「重试全部失败」+「重试元数据补全」合并为「重试失败项」（勿再拆分） |
| beta.14/15/16 | 前台文案三轮重写（已定稿，勿擅改） |
| beta.17 | 删图补删三档缩略图 + 失败审计；DB 侧始终硬删除，无软删除 |
| beta.18 | 审计 P1：会话硬化/角色强制/Key 哈希/又拍云签名/孤儿回收/发布链+CI/凭据脱敏 |

其他硬约束：
- 图库前台（/gallery）显示位约 275×206，**必须用缩略图而非原图**（用户多轮强调，别改错成后台图库）。
- 无图优雅空态，不裸 404。**分页设计不能改**。
- **不兼容 v2.0.0 前有架构改动的版本**（见 `docs/PLAN-CANCEL-COMPAT-2026-09-28.md`）。
- 缩略图 size 回退链、API JSON/302 双模式、限流 fail-closed 是既有契约。

---

## 6. 本机环境与验证

- Windows + PowerShell（非 bash）：`&&`/heredoc/jq/sed/awk 不可用；复杂逻辑写 Python（显式 `encoding='utf-8'`）。
- **无 PHP CLI / MySQL / composer**；有 node、python、git。
- Git 推送代理：`-c http.proxy=http://127.0.0.1:7897`。
- 令牌：`.dsh/moerng.token`（**禁止打印/进 commit/日志**）；推送 URL `https://Grabrun:<token>@github.com/Grabrun/MoeRNG.git`；新发布脚本走环境变量（MR-07/26）。
- 验证三件套 + 专项断言见第 3.4 节。逻辑修复先例：`.dsh/verify_delete_keys.js`、mr0*.js、p2_*.js。
- 未验证边界：真实 SQL、对象存储、Cookie 属性、并发时序——只能静态+node 复刻，交付时注明线上复测命令（报告每条有「验证方法」）。

---

## 7. 发布 SOP（每批收尾严格执行，勿跳步）

1. 全量 harness 绿 + 本批新增断言绿（P2 收尾还要把 .dsh 的 p2_*.js 迁入 `scripts/harness/`）。
2. **补提交 `.github/workflows/ci.yml`**（beta.18 遗漏）。
3. bump 版本（唯一定义 `src/bootstrap.php:33`）：仿 `docs/scripts/patch_m15_version.py` 写 patch_m16（beta.19）；CHANGELOG 在最新锚点上方插条目，每项一行中文。
4. 构建：`python docs\scripts\build_release.py`（或新链路 `python scripts\release.py`，产出 zip + .sha256；SDK 由 `_package.py` 打包注入；自带凭据扫描）。
5. `git add` 前确认 .gitignore 挡住 `.dsh/`、`*.token`、`src/config/*.php`、`releases/`、`tools/`（MR-25，建议显式 add 而非 -A）；conventional commit。
6. push main（代理 + 令牌 URL，不回显）。
7. 建 Release：仿 `docs/scripts/release_github_beta18.py` 写 beta19，prerelease，传 zip + sha256；MR-27 建议改 draft:true 复核后发。
8. `git tag v2.0.0-beta.19` → push tag → **GitHub API 复核远程 tag SHA == 本地 HEAD**（beta.9/10 教训：REST 自动建 tag 本地缺失，必须双留）。
9. 归档纪律（硬约束）：历史 zip 永不覆盖，全部留 `releases\`，可读版本命名。

---

## 8. 用户偏好与工作约定（硬约束）

1. 「执行/确认」类指令直接干活；破坏性/不可逆操作（删数据、线上、密钥轮换）先说明影响。
2. 构建产物可读版本命名 + 归档历史 + 保留迭代记录，zip 不覆盖。
3. 中文优先（文案/CHANGELOG/交付说明）。
4. 分页设计不能改；前台文案不擅改；不兼容 v2 前架构。
5. 交付说明必含：改了什么、怎么验证、**哪些未验证及原因**、对存量数据/账号影响。
6. 密钥只报位置与类型，不贴明文、不入库、不进日志。
7. 踩坑按「现象→根因→解决→预防」记录。

---

## 9. 风险点与注意事项

- **P2 半成品是资产不是垃圾**：harness 全绿、4 套断言全过；但被取消时 MR-38 埋点与 MR-20/42 正在收尾，提交前必须按第 3.2 表「收尾动作」列逐项复核，不得不加验证直接 bump 发版。
- **schema 变更幂等 + dry-run**：MR-09 唯一键在已存在重复行的库上 ADD UNIQUE 会失败——上线迁移需先去重（Application 运行时自迁移机制要考虑，参考 MR-58 顺序错位教训）；MR-03 迁移 CLI 是模板。
- **beta.18 上线动作未完成**：API Key 哈希迁移 CLI 需要用户在线上执行（旧 key 全失效）；ci.yml 漏提交意味着 beta.18 无 CI 保护。
- **密钥体系三处**：存储凭据 `config/credentials.php`（AES-256-GCM，丢失=全不可解，MR-49）；签名 `config/signing_key.php`（P2 已 chmod 0640）；API Key（P1 后哈希）。
- **删除链权威**：缩略图以 `images.thumbs` JSON 为准（thumb_path 仅冗余）；走 `Image::delete()`+`decodeThumbMap()`；裸 DELETE 是 MR-05 反模式。StorageInterface 无 LIST，历史孤儿只能控制台按 `{yyyy}/{mm}/{uuid}/` 前缀人工清。
- **云驱动 delete 语义** P2 已对齐（MR-40），但真实云端行为未验证。
- **SDK 供应链**：`src/sdk/` 不入库打包注入；COS dev-master（MR-06 未解）；6 家共用一棵 Guzzle（MR-24 未解）；无 SBOM（MR-68）。
- doctor.php 随包发布（MR-70），部署后建议删；MAINTAINER-GUIDE 滞后（MR-72）。

---

## 10. 接手后立即待办（按优先级）

1. **P2 收尾（beta.19）**：
   a. 重跑第 3.4 全部断言确认绿；
   b. 补做未开始项中高优先且低成本：**MR-13**（0.25 人日）、**MR-29**（0.25 人日，防备份泄露）、MR-22（XSS）、MR-19；
   c. 复核待确认项：MR-20（超时代码）、MR-42（url catch 语义）、MR-38（逐动作对表）、MR-32（备份是否异步）、MR-11（异常 catch 面）、MR-17（删除拦截提示）；
   d. 补 ci.yml 提交 + p2/mr 测试迁入 scripts/harness；
   e. 其余 P2 项（MR-24/25/26/27/30 + audit_logs 保留）按条件推进；
   f. bump beta.19 → 发布 SOP 9 步。
2. **P3（beta.20）**：按第 3.3 节清单，代码类与文档类同批交付，最后更新 MAINTAINER-GUIDE（MR-72）与本文。
3. **MR-06**：找有 composer+网络的环境换 COS SDK 发布 tag 或评估替代驱动。
4. **线上复测（需 PHP+MySQL）**：Cookie 属性、editor 403、Key 迁移、又拍云签名、孤儿回收、MR-09 唯一键迁移去重、CI 首跑。
5. 每批完成回写本文状态表。

---

## 11. 关键参考索引

- 审计主报告：`docs/audit/MoeRNG-深度审计报告-2026-10-11.md`；风险 HTML 同目录
- MR-06 受限说明：`docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md`
- ADR：`docs/decisions/ADR-001-api-thumbnail-size.md`、`OPEN-DECISIONS.md`
- 取消兼容：`docs/PLAN-CANCEL-COMPAT-2026-09-28.md`；维护指南（滞后）：`docs/MAINTAINER-GUIDE.md`
- 发布脚本：`scripts/release.py`（新，入库）、`docs/scripts/build_release.py`、`release_github_beta18.py`
- 验证 harness：`.dsh/*.js`（本地全量）；`scripts/harness/`（CI 副本，P1 入库）
- 本地长期记忆：`.dsh/memory/MEMORY.md`（不入库）

---

*交接文档结束。任何与实际代码冲突之处，以代码为准并回写修正本文。*

