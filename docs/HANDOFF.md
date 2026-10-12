# MoeRNG 项目交接文档（Agent Hand-off）

> **撰写时间**：2026-10-12
> **撰写时版本**：v2.0.0-beta.17（`src/bootstrap.php` APP_VERSION，HEAD `d660e2d`）
> **文档性质**：给下一个接手 Agent 的操作手册。长期心智模型另见 `docs/MAINTAINER-GUIDE.md`（注意：该文件滞后到 beta.1，见 MR-72）与 `.dsh/memory/MEMORY.md`（本地，不入库）。
> **阅读顺序**：先读第 0 章「接手第一步」并逐条执行，再按需查其余章节。

---

## 0. 接手第一步（TL;DR）

1. **有一个修复任务正在后台运行**：组织代理 `o_000E5baRs2S`（标题「MoeRNG审计P1修复」）正在执行审计报告的全量修复（P1→P2→P3，对应 beta.18/19/20）。接手时先用 `peek_task_progress(organizer_ids=["o_000E5baRs2S"])` 查状态；若已结束，读取其最终交付（每条修复的变更摘要、验证结果、Release URL、tag SHA）。**不要重复委派同一范围**。
2. **核对工作区**：`git status` + `git log --oneline -5` + `git tag --sort=-creatordate | head`。撰写本文时 P1 改动**尚未提交**（16 个已修改文件 + 新增 `.github/`、`scripts/`、`RequireAdminMiddleware.php`、`src/cli/migrate-apikey-hash.php`）。
3. **核对发布链**：`releases\` 最新 zip、GitHub Releases（https://github.com/Grabrun/MoeRNG/releases）、本地 tag 与远程 tag SHA 是否一致。
4. **读审计报告**：`docs/audit/MoeRNG-深度审计报告-2026-10-11.md`（1467 行，72 条发现 MR-01~MR-72，每条含证据与修复建议）。修复进度以本文第 3 章 + 实际 git 状态为准，冲突时以实际代码为准。
5. **本机环境限制（重要）**：**无 PHP CLI、无 MySQL、无真实对象存储凭据**。所有验证只能做：PHP 语法/AST 静态检查（node harness）、JS 语法检查、node 逻辑复刻断言、调用链静态追踪。**运行时行为（真实 SQL、真实上传/签名 URL、真实会话 Cookie）一律标「未验证」**，不得声称跑通过。

---

## 1. 项目身份

| 项 | 值 |
|---|---|
| 定位 | 随机二次元图片 API（JSON / 302 redirect）+ 自托管萌系图床 + 管理后台 |
| 线上 | https://images.grabrun.top（宝塔 Nginx + PHP-FPM） |
| 仓库 | https://github.com/Grabrun/MoeRNG（public，分支 main，身份 Grabrun） |
| 本地路径 | `E:\Projects\DouBao\MoeRNG` |
| 技术栈 | PHP 8.4 + MySQL；PSR-4 自研轻量框架（**无 Composer 运行时**）；前端原生 JS（无构建） |
| 存储 | 8 个驱动：Local / 腾讯云 COS / 阿里云 OSS / S3 兼容 / 华为云 OBS / 七牛 Kodo / 又拍云 / AWS SDK（`src/app/Storage/` + `src/sdk/`） |
| doc-root | **项目根（非 public/）**；入口 `src/index.php`、`src/api.php`、`src/admin.php`、`src/install.php`、`src/doctor.php` |
| 当前版本 | v2.0.0-beta.17；修复完成后依次 bump beta.18（P1）/ beta.19（P2）/ beta.20（P3） |
| 稳定版 | v1.4.0（历史 tag）；**v1.5 系列从未发版**（开发线被 v2 重构取代，能力已并入 beta 线，见 CHANGELOG 谱系说明） |

### 请求链

```
浏览器/API 客户端
  ├─ /          (index.php)  → HomeController：首页/图库/API文档/tester/关于
  ├─ /api/v1/*  (api.php)    → ApiKeyAuth → RateLimit → ApiController（random/list/categories/stats）
  ├─ /admin/*   (admin.php)  → AuthMiddleware → (RequireAdmin) → Csrf → Admin\Controllers
  ├─ /files     (FileController) → SignedUrl HMAC 校验 → 多根回退 → 流式输出（本地媒体）
  ├─ install.php → 安装向导（installed 锁；覆盖安装不重显，beta.7 修复）
  └─ doctor.php  → 诊断端点（限管理员会话，部署后建议手工删，MR-70）
```

---

## 2. 代码地图

```
MoeRNG/
├─ src/
│  ├─ bootstrap.php          # 版本常量 APP_VERSION（唯一定义处，:33）+ 自动加载 + 装配
│  ├─ schema.sql             # 数据库 schema（安装与运行时自迁移的权威）
│  ├─ index.php/api.php/admin.php/install.php/doctor.php   # 各入口
│  ├─ nginx.conf.example     # 部署伪静态（MR-29：需补 deny backups/var）
│  ├─ app/
│  │  ├─ Core/               # Application, Router, Database(PDO), Session, Request/Response,
│  │  │                      #   Model, Config, CspNonce, CredentialCipher(AES-256-GCM),
│  │  │                      #   RateLimiter, Captcha, Mailer, Stats, BackupService, Controller
│  │  ├─ Middleware/         # AuthMiddleware, CsrfMiddleware, RateLimitMiddleware,
│  │  │                      #   ApiKeyAuthMiddleware, ApiAuthMiddleware(死代码 MR-53),
│  │  │                      #   RequireAdminMiddleware.php（P1 新增）
│  │  ├─ Controllers/        # Api/File/Home/Install Controller
│  │  │  └─ Admin/           # Auth/Dashboard/Image/Category/User/ApiKey/StorageProfile/Setting
│  │  ├─ Models/             # User, Image, Category, ApiKey, StorageProfile, Setting, AuditLog
│  │  └─ Storage/            # StorageInterface + LocalDriver + 6 云驱动 + S3Driver(兼容) + SignedUrl
│  ├─ cli/                   # CLI 工具：backfill-thumbs*.php、migrate-apikey-hash.php（P1 新增）
│  ├─ public/js/             # front.js（前台）、app.js（后台）、helpers.js（MR-65 有重复块）
│  ├─ views/                 # admin/、install/、partials/（PHP 视图，h() 转义纪律）
│  ├─ sdk/                   # 6 家官方 SDK（.gitignore 忽略，打包时注入，见第 6 章）
│  ├─ config/                # .htaccess 入库；app.php/database.php/signing_key.php/credentials.php 不入库
│  └─ storage/               # uploads/（媒体，web 根外）、incoming/（上传暂存）；var/（限流计数）
├─ docs/
│  ├─ audit/                 # 审计报告与历史审计（见第 3 章）
│  ├─ decisions/             # ADR-001-api-thumbnail-size.md、OPEN-DECISIONS.md
│  ├─ scripts/               # 历史 patch_*/release_github_beta*.py（beta.9~17 迭代脚本，已入库）
│  ├─ DEV-PLAN.md、MAINTAINER-GUIDE.md（滞后 beta.1）、PLAN-CANCEL-COMPAT-2026-09-28.md
├─ scripts/                  # 【P1 新增，入库】release.py + _package.py + harness/（替代 gitignored 的 tools/）
├─ .github/                  # 【P1 新增】workflows/ci.yml + dependabot.yml
├─ tools/                    # 旧发布脚本（.gitignore 忽略，MR-07 后由 scripts/ 取代）
├─ .dsh/                     # 本地工作区（.gitignore 忽略）：token、memory、验证 harness（见第 5 章）
├─ releases/                 # 历史发布 zip 归档（.gitignore 忽略），命名 MoeRNG-<ver>-<时间戳>.zip
├─ CHANGELOG.md              # Keep a Changelog 格式，中文
└─ README.md
```

---

## 3. 审计与修复进度（最重要）

### 3.1 审计产物

| 文件 | 说明 |
|---|---|
| `docs/audit/MoeRNG-深度审计报告-2026-10-11.md` | **主报告**，1467 行，72 条发现（8 High / 22 Medium / 21 Low / 21 Info），第 3 章总览表、第 4 章逐条证据+修复建议、第 6 章路线图 |
| `docs/audit/MoeRNG-审计风险分布-2026-10-11.html` | 风险分布可视化 |
| `docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md` | MR-06 受限说明（无 composer 环境，P1 期间产出） |
| `docs/audit/2026-09-2*.md` | 更早三轮审计 + 图片架构审计（历史背景） |

全部 8 条 High 与抽查的 6 条 Medium（MR-12/21/22/33/34/36）已经独立回源码/官方文档复核，**全部属实**。唯一行号偏差：MR-07 的 `.gitignore:36` 实为 `:35`。

### 3.2 分批修复计划（用户已确认「修复全部问题」）

| 批次 | 版本 | 范围 | 撰写时状态 |
|---|---|---|---|
| P1 | beta.18 | MR-01/02/03/04/05/07/08 + MR-12/23/36（MR-06 受限） | **进行中 78%**（见 3.3） |
| P2 | beta.19 | MR-09~MR-43 中全部可代码化 Medium/Low | 未开始（清单见 3.4） |
| P3 | beta.20 | MR-44 起 Low/Info 可代码化项 + 文档类 | 未开始（清单见 3.5） |

### 3.3 P1 详细状态（撰写时，以组织代理最终交付为准）

| ID | 内容 | 状态 | 关键落地点 |
|---|---|---|---|
| MR-01 | 会话 Cookie 硬化被架空 | ✅ 完成 | `CspNonce.php` 三处裸 `session_start()`→`Session::start()`；`Application.php` bootstrap 统一入口（:56 区，先于 sendSecurityHeaders）；`.dsh/mr01_session_test.js` 绿 |
| MR-02 | editor 垂直越权 | ✅ 完成 | 新增 `Middleware/RequireAdminMiddleware.php`；`admin.php` 23 条高风险路由挂守卫；`UserController` role 白名单（非 admin 不能建 admin）；`.dsh/mr02_role_guard_test.js` 绿 |
| MR-03+MR-23 | API Key 哈希化 + 列表脱敏 | ✅ 完成 | `schema.sql` key→`key_hash CHAR(64)`+`key_prefix VARCHAR(24)`；`ApiKey.php` hashKey/findByKey（SHA2）；Controller present 掩码；视图去 `data-key`；app.js 去复制明文路径；**迁移 CLI `src/cli/migrate-apikey-hash.php`（dry-run 默认）**；6 个消费点已审计自适应 |
| MR-05 | 云端孤儿对象回收 | ✅ 完成 | `ImageController` 上传 catch 回滚已上云对象 + queueClear 删库前先回收 path/thumbs；`.dsh/mr05_orphan_test.js` 21 断言绿 |
| MR-04 | 又拍云签名官方算法 | ⏳ 进行中 | `UpyunSdkDriver::url()` 重写 `_upt=substr(md5(token_key&etime&path),12,8).etime`；新增 token_key 配置字段；未配置返回裸 CDN URL |
| MR-12 | 存储凭据回显脱敏 | ⏳ 进行中 | `StorageProfileController` 新增 toClientItem() 掩码 secret；update 传 keepSecrets（保留「留空=不改」语义）；storage.php 视图 data-config 过滤 |
| MR-36 | AccessKeyId 纳入密封 | ⏳ 进行中 | `CredentialCipher::isSecretField()` 扩名单（随 MR-04 同组） |
| MR-07 | 发布脚本入库 + SHA256 | ⏳ 进行中 | 新增入库 `scripts/release.py`（环境变量取 token、确定性 zip、输出 .sha256）+ `scripts/_package.py` |
| MR-08 | CI 门禁 | ⏳ 进行中 | `.github/workflows/ci.yml` + `dependabot.yml`；harness 复制到 `scripts/harness/`（syntax_check/php_undef_check/xref_audit）入库 |
| MR-06 | COS SDK 钉 dev-master | ⚠️ 受限 | 本机无 composer/PHP，无法换 tag 重拉；已出 `docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md`，需有网络+composer 的环境处理 |

**P1 收尾未完成动作**（接手时核对）：MR-04/12/36/07/08 代码收尾 → 全量 harness 复跑 → bump beta.18（仿 `docs/scripts/patch_m14_version.py` 写 patch_m15）→ build_release → commit/push → Release（含 .sha256）→ tag 本地+远程 SHA 复核。

**存量影响（必须在交付说明告知用户）**：
- MR-02：现有 editor 账号权限立即收紧（仅上传/分类/浏览）。
- MR-03：**迁移 apply 后存量 API Key 全部重发、旧 key 失效**；新明文 key 仅输出一次到迁移报告文件，需把报告文件路径交给用户。

### 3.4 P2 待办（beta.19，Medium 为主）

限流原子性（MR-09 rate_limits 唯一键+原子 upsert、MR-10 文件限流 LOCK_EX 覆盖读改写）、MR-11 密钥不可用 fail-closed 不静默明文、MR-13 `/api/v1/images` 补 `process_status='done'`、MR-14 logo 扩展名白名单+.htaccess 修正、MR-15 processQueue 复位削弱条件认领、MR-16 Category 递归深度闸+事务、MR-17 storage_profile_id FK/软外键、MR-18 LocalDriver `..` 规范化、MR-19 SVG 内联 disposition/nosniff、MR-20 云驱动超时（七牛 curl）、MR-21 S3 三处 TLS verify 开启、MR-22 tester innerHTML 转义（front.js:210 等，注意 app.js:289 有副本）、MR-24 六家 SDK 共用 Guzzle 树、MR-25 git add -A 防护、MR-26 PAT 轮换纪律、MR-27 Release draft 复核门、MR-28 signing_key.php chmod 0640、MR-29 nginx 例配补 deny、MR-30 隐私/数据保留合规文档、MR-31 Host 头注入、MR-32 每请求探测缓存+备份异步化、MR-33/34 random 并发空结果/补齐去重、MR-35 分类树 N+1 缓存、MR-37 backfillThumbs 并发认领、MR-38 高危操作审计留痕+保留策略、MR-39 endpoint SSRF 校验、MR-40 delete 语义统一、MR-41 签名 URL 时间窗口对齐、MR-42 预签名失败不静默降级裸 URL、MR-43 downloadUrl 落盘大小上限。

### 3.5 P3 待办（beta.20，Low/Info）

MR-44 flash 白名单属性清理、MR-45 uploads/.htaccess（-Indexes/nginx 说明）、MR-46 OBS monolog 未 vendor、MR-47 release_github.py 漏 import urllib.parse、MR-48 历史 strip_/patch_ 脚本硬编码路径（归档说明）、MR-49 密钥轮换/备份策略文档、MR-50 install 写配置 chmod、MR-51 README 配置模板漂移、MR-52 CSP nonce 重复生成+base-uri、MR-53 删 ApiAuthMiddleware 死代码、MR-54 AuditLog 写失败告警、MR-55 bootstrap 失败页生产模式脱敏、MR-57 status ENUM 死值清理、MR-58 迁移顺序死块、MR-59 testConnection 上传源码 __FILE__、MR-60 CosSdkDriver 注释漂移、MR-62 死视图 layout.php、MR-63/64/67 转义一致性、MR-65 helpers.js 主题块重复×4+toast 恒 alert、MR-66 users flash 双显、MR-68 SDK SBOM、MR-70 doctor.php 部署后删除提示、MR-72 更新 MAINTAINER-GUIDE 版本线。MR-56（install CSRF）为设计豁免（窗口极短），记录即可。MR-61/69 是正面验证项。

---

## 4. 历史迭代脉络（beta.9~17，避免重复踩坑）

| 版本 | 主题 |
|---|---|
| beta.9 | 前端性能优化：preconnect/字体异步/.htaccess 缓存/front.js 拆分 |
| beta.10 | 首页白屏 hotfix：视图 CspNonce 全限定名 |
| beta.11 | 前台图库缩略图一键补全 CLI（backfill-thumbs） |
| beta.12 | **处理队列触发策略重构：仅「上传完成」与「图片处理页手动按钮」触发，不随时自动处理**（用户明确要求，勿改回） |
| beta.13 | 图片处理页「重试全部失败」+「重试元数据补全」合并为「重试失败项」（用户明确要求，勿再拆分） |
| beta.14/15/16 | 前台文案三轮重写（静态视图→front.js 运行时→整体重写） |
| beta.17 | 删除图片补删三档缩略图（thumb-sm/md/lg.webp），失败记 `image_delete_storage_failed` 审计；DB 侧始终硬删除，无软删除 |

其他既定事实：
- 图库前台（/gallery）显示位约 275×206，**必须用缩略图而非原图**（用户多轮强调，别改错成后台图库）。
- 图片无图时要优雅空态，不能裸 404（用户明确要求）。
- **分页设计不能改**（用户明确约束）。
- **不再兼容 v2.0.0 之前有架构改动的版本**（用户明确要求；见 `docs/PLAN-CANCEL-COMPAT-2026-09-28.md`）。
- 缩略图 size 回退链、API JSON/302 双模式、速率限制 fail-closed 均为既有契约，修复不得破坏。

---

## 5. 本机环境与验证 harness

### 5.1 环境

- Windows 10/Server，PowerShell（**不是 bash**）：`&&`、heredoc、jq/sed/awk 不可用；复杂逻辑写 Python（显式 `encoding='utf-8'`，防 BOM/代码页乱码）。
- **无 PHP CLI、无 MySQL、无 composer**；有 node、python、git。
- Git 推送走本地代理：`-c http.proxy=http://127.0.0.1:7897`。
- GitHub 令牌：`.dsh/moerng.token`（**禁止打印、禁止进 commit/日志/版本控制**）；推送 URL 形态 `https://Grabrun:<token>@github.com/Grabrun/MoeRNG.git`。新版发布脚本改为环境变量取 token（MR-07/26）。

### 5.2 验证命令（每次改完必跑）

```powershell
node .dsh\syntax_check.js        # 全量 PHP 语法检查（撰写时规模 2916 文件次）
node .dsh\php_undef_check.js     # PHP AST 未定义变量扫描（57+ 文件）
node --check src\public\js\front.js
node --check src\public\js\app.js
```

P1 后 harness 已复制入库到 `scripts/harness/`（CI 用）；本地 `.dsh/` 副本继续可用，两处保持同步。

逻辑类修复写 node 复刻断言（先例：`.dsh/verify_delete_keys.js`、`.dsh/mr01_session_test.js`、`mr02_role_guard_test.js`、`mr03_apikey_hash_test.js`、`mr05_orphan_test.js`）。`.dsh/` 下还有大量历史契约测试（api_contract_test、queue_contract_test、thumbs_contract_test、click_matrix_test 等），改动相关模块时应复跑对应测试。

### 5.3 未验证边界（诚实标注）

真实数据库 SQL 行为、对象存储上传/删除/签名 URL、会话 Cookie 属性、限流并发时序——本机均无法运行，只能静态验证 + node 复刻。交付时这些项写「未验证，需在 PHP+MySQL 环境/线上复测」，并给出复测命令（报告每条发现的「验证方法」列）。

---

## 6. 发布 SOP（每批次修复后严格执行）

历史 beta.9~17 已固化的发布链，**不得跳步**：

1. **全量验证**：第 5.2 节 harness 全绿 + 本批新增断言全绿。
2. **bump 版本**（版本号唯一定义在 `src/bootstrap.php:33` APP_VERSION）：
   - 仿 `docs/scripts/patch_m14_version.py` 写 `patch_m15_version.py`（P1 用），改 APP_VERSION + 在 CHANGELOG 锚点（当前 `## [2.0.0-beta.17] - 2026-10-03`）上方插入新条目，每修复项一行中文说明。
3. **构建归档**：`python docs\scripts\build_release.py`
   - 输出 `releases\MoeRNG-<ver>-<yyyyMMdd-HHmmss>.zip`；
   - 脚本自带凭据扫描与 CRC（MR-69 正面项）；
   - SDK 目录 `src/sdk/` 在打包时由 `_package.py` 注入（仓库不跟踪，67MB vendor）；
   - P1 起新链路 `scripts/release.py` 产出同名 zip **+ `.sha256`**（MR-07）。
4. **提交**：`git add -A`（MR-25 风险：提交前必须确认 `.gitignore` 已挡住 `.dsh/`、`*.token`、`src/config/*.php`、`releases/`、`tools/`）+ conventional commit（分条列修复）。
5. **推送 main**：代理 + 令牌 URL（见 5.1），令牌不回显。
6. **建 GitHub Release（prerelease）**：仿 `docs/scripts/release_github_beta17.py` 写 beta18 脚本，上传 zip + sha256。MR-27 建议改 draft:true 人工复核后发布（P2 落地）。
7. **tag**：`git tag v2.0.0-beta.18` → push tag → **用 GitHub API 复核远程 tag SHA == 本地 HEAD SHA**（beta.9/10 曾出现 REST API 自动建 tag 而本地缺失的事故，教训：本地与远程必须双留）。
8. **归档纪律（用户偏好，硬约束）**：历史版本 zip 永不覆盖，全部留在 `releases\`；可读版本命名；dist/打包目录只放最新版。

历史发布脚本在 `docs/scripts/release_github_beta9~17.py`（每版一个，可直接复制改名）。注意 MR-47：最早的 `release_github.py` 漏 `import urllib.parse`，重跑必崩，用 beta17 之后的版本。

---

## 7. 用户偏好与工作约定（硬约束）

1. **「执行/确认」类指令直接干活，不反复请示**；但破坏性/不可逆操作（删数据、影响线上、密钥轮换）先说明影响。
2. **构建产物可读版本命名 + 归档历史版本 + 保留迭代记录**：zip 不覆盖、逐版归档。
3. **中文优先**：界面文案、CHANGELOG、交付说明均用中文。
4. **分页设计不能改**；前台文案不擅自改（beta.14~16 已多轮重写定稿）。
5. **不兼容 v2.0.0 前架构改动版本**，不写向后兼容迁移包袱。
6. 交付说明必须包含：改了什么、怎么验证的、**哪些未验证及原因**、对存量数据/账号的影响。
7. 项目管理类任务输出结构化任务清单（任务粒度、分类、依赖）。
8. 发现密钥/令牌：**只报位置与类型，不贴明文**，不入库、不进日志。
9. 开发踩坑分类记录纪律（用户在另一项目确立的习惯，本项目同样适用）：现象→根因→解决→预防。

---

## 8. 风险点与注意事项

- **工作区可能处于半修复状态**：P1 组织代理运行期间，文件可能被并发修改。接手后不要凭本文档假设文件内容，先 `git status`/`git diff` 看实际状态，再读文件。
- **schema 变更必须幂等 + dry-run**：MR-03 的 `migrate-apikey-hash.php` 是模板（默认 dry-run，apply 才写库）。Application 有运行时自迁移机制（`Application.php` 内 migration 块），改 schema 时注意 MR-58（迁移顺序错位的历史教训：backfill 块被 DEFAULT 兜底成死代码）。
- **密钥体系三处**：① 存储凭据 `CredentialCipher` AES-256-GCM，密钥文件 `src/config/credentials.php`（不入库，丢失=全部云凭据不可解，MR-49）；② 本地签名 `src/config/signing_key.php`（MR-28 需 chmod 0640）；③ API Key（P1 后哈希落库）。
- **删除链权威数据源**：图片缩略图登记以 `images.thumbs` JSON 为准（`thumb_path` 列只是 md 档冗余）；删除走 `Image::delete()` + `Image::decodeThumbMap()`；裸 `DELETE FROM images` 是 MR-05 反模式。
- **StorageInterface 无 LIST 能力**：历史孤儿对象无法程序枚举，只能按 `{yyyy}/{mm}/{uuid}/` 前缀到对象存储控制台人工清理（CHANGELOG beta.17 已告知用户）。
- **云驱动 delete 语义不一致**（MR-40）：本地删不存在 key 返回 false（产生审计噪声），云端幂等返回 true——做回收逻辑时失败容忍要考虑这点。
- **SDK 供应链**：`src/sdk/` 不入库、打包注入；COS 钉 dev-master（MR-06 未解决）；6 家共用 cos/vendor 一棵 Guzzle（MR-24）；无 SBOM（MR-68）。动 SDK 前先读 `docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md`。
- **doctor.php 随包发布**（MR-70）：线上部署后应手工删除或限制访问。
- **MAINTAINER-GUIDE.md 滞后**（MR-72）：其架构描述仍有参考价值，但版本线、部分路径停在 beta.1，以代码与本文为准。

---

## 9. 常用命令速查

```powershell
# 状态核对
git status --short
git log --oneline -10
git tag --sort=-creatordate | Select-Object -First 12
git rev-parse HEAD

# 验证三件套
node .dsh\syntax_check.js
node .dsh\php_undef_check.js
node --check src\public\js\front.js ; node --check src\public\js\app.js

# 单个契约断言（示例）
node .dsh\mr05_orphan_test.js

# 构建发布
python docs\scripts\build_release.py

# API Key 哈希迁移（P1 新增；先 dry-run）
php src/cli/migrate-apikey-hash.php            # dry-run（本机无 php，需线上/PHP 环境执行）
php src/cli/migrate-apikey-hash.php --apply    # 正式迁移（旧 key 全失效，注意保存报告）

# 推送（令牌从文件读，勿回显）
git -c http.proxy=http://127.0.0.1:7897 push origin main
git -c http.proxy=http://127.0.0.1:7897 push origin v2.0.0-beta.18
```

---

## 10. 接手后立即待办（按优先级）

1. **接管 P1 收尾**：查 `o_000E5baRs2S` 最终状态 → 核对 MR-04/12/36/07/08 代码 → 全量 harness → bump beta.18 → 发布链 8 步 → 交付（含 MR-03 存量 key 重发报告路径）。
2. **P2（beta.19）**：按 3.4 清单逐单元「读报告证据→源码复核→设计→实现→harness→node 断言」，建议分片：并发/限流组（09/10/15/33/34/37）、安全组（11/14/18/19/21/22/28/31/39/42/43）、数据一致性组（13/16/17/35/38/40/41）、工程化组（20/24/25/26/27/29/30/32）。
3. **P3（beta.20）**：按 3.5 清单收尾，文档类（MR-30/49/51/72）与代码类同批交付。
4. **MR-06**：找有 composer+网络的环境把 COS SDK 换到发布 tag，或评估替换为其他 S3 兼容驱动。
5. **上线后复测清单**（需 PHP+MySQL 环境）：Cookie 属性（curl -v 看 Set-Cookie）、editor 越权 403、新 API Key 鉴权、又拍云防盗链签名 URL 可访问、上传失败回滚、queueClear 回收、CI 首次运行结果。
6. 全部完成后更新本文与 `docs/MAINTAINER-GUIDE.md`（MR-72）。

---

## 11. 关键参考文件索引

- 审计主报告：`docs/audit/MoeRNG-深度审计报告-2026-10-11.md`
- 架构决策：`docs/decisions/ADR-001-api-thumbnail-size.md`、`docs/decisions/OPEN-DECISIONS.md`
- 取消兼容计划：`docs/PLAN-CANCEL-COMPAT-2026-09-28.md`
- 维护指南（滞后）：`docs/MAINTAINER-GUIDE.md`
- 开发计划：`docs/DEV-PLAN.md`
- 变更日志：`CHANGELOG.md`
- 本地长期记忆（不入库）：`.dsh/memory/MEMORY.md`
- 验证 harness（不入库，P1 后有入库副本 scripts/harness/）：`.dsh/*.js`
- 发布脚本（入库）：`docs/scripts/build_release.py`、`docs/scripts/release_github_beta*.py`、`scripts/release.py`（新）

---

*交接文档结束。任何与实际代码冲突之处，以代码为准并回写修正本文。*

