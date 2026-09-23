# MoeRNG 维护指南（Maintainer Guide）

> 本文件由 Doubao 接管维护时建立（2026-09-23），是后续迭代的**心智模型与工作基线**。
> 长期纪律仍以 `.dsh/memory/MEMORY.md` 为准（本文件是其结构化摘要 + 快照，两处冲突时以 MEMORY.md 为准并回写本文件）。

## 1. 项目身份

| 项 | 值 |
|---|---|
| 形态 | 随机二次元图片 API（JSON / 302 redirect）+ 自托管图床 + 管理后台 |
| 技术栈 | PHP 8.4（PSR-4 自研框架，无 Composer 运行时）+ MySQL + Redis（session） |
| 部署形态 | 宝塔 Nginx + PHP-FPM；**doc-root = 项目根（非 public/）**；线上 images.grabrun.top |
| GitHub | https://github.com/Grabrun/MoeRNG（public, main；身份 Grabrun） |
| 当前版本线 | 代码 `APP_VERSION = 1.5.0-beta.1`（bootstrap.php 唯一定义处）；迭代记录已到 beta.3，**未发布** |
| 稳定版 | v1.4.0（tag v1.4.0）；上一稳定版 v1.3.2 |

## 2. 架构分层与请求链

```
浏览器/API 客户端
  │
  ├─ / (index.php)          → HomeController / Gallery / Docs / Tester / About
  ├─ /api/v1/* (api.php)    → ApiKeyAuth → RateLimit → ApiController（random/list/categories/stats）
  ├─ /admin/* (admin.php)   → AuthMiddleware → Csrf → Admin\Controllers（图片/分类/用户/APIKey/存储/设置/队列）
  ├─ /files (FileController)→ SignedUrl HMAC 校验 → 多根回退定位 → 流式输出（本地媒体）
  ├─ install.php            → 安装向导（installed 锁，GET 回退只重渲染）
  └─ doctor.php             → 诊断端点（发布后即删）
```

- **框架核心** `src/app/Core/`：Application（装配 + 安全头 + 运行时自迁移）、Router（静态表）、Config、Database（PDO）、Session（Redis）、Request/Response、Model（fillable/hydrate 过滤）、RateLimiter、CredentialCipher（AES-256-GCM）、SignedUrl、CspNonce、Stats、Mailer、BackupService、Captcha。
- **中间件**：Auth / ApiKeyAuth / Csrf / RateLimit（fail-closed）。
- **存储抽象** `src/app/Storage/`：`StorageInterface`（upload/delete/url/exists/size + 静态 configFields/name 六方法铁律）→ `LocalDriver`（签名 `/files`，媒体根默认 `storage/uploads` 在 web 根之外）→ `S3Driver`（纯调度器，委托 6 家官方 SDK：cos/oss/aws/obs/upyun/qiniu，`src/sdk/` 不进 git）。
- **数据**：MySQL（schema.sql 新装基线 + Application 运行时自迁移）+ Redis session + 文件系统；`storage_profiles` 是存储唯一配置来源。

## 3. 关键机制（改了必读对应契约测试）

| 机制 | 要点 | 守护测试 |
|---|---|---|
| 图片处理流水线 | 上传 → 原图转 WebP（先算哈希）→ process_status 队列（pending/processing/done/failed，前台只出 done）→ 缩略图 sm/md/lg（webp q82）→ processing_state JSON 按处理项分键（`{"thumb_meta":"partial"}`，缺键=pending）→ thumb_bytes 实测入库 | queue_contract / memory_guard / thumbs_contract / layout_contract §14 |
| 失败行处置 | 失败离开队列（写状态不阻塞）+ 定向重试（扫描游标 from/next_from，防无限循环）；硬失败保持 pending 自动重试 | queue_contract（含行为模拟） |
| API 载荷 | 单一 `url` 字段 = 按 `size` 解析后那张图（sm/md/lg/original，**缺省 original**）+ 极短 `size` 如实回报；所有字段描述 url 指向那张图；`/random` 必须 `no-store`，`/images` 允许 `private, max-age=30` | api_contract |
| 缩略图尺寸 | `Image::thumbDimensions()` 唯一实现，生成端与读取端共用；兜底链只在 `displayUrlWithSize()`（请求→md→原图） | thumbs_contract |
| 运行时自迁移 | `SCHEMA_VERSION` 门控；`SHOW COLUMNS` 探测 + ALTER 吞 1060 幂等；best-effort 不静默吞错（写 migration_last_error） | audit_data_security |
| 存储解析 | `StorageProfile::find()/driverForImage()` 请求内缓存；`/files` 默认根→历史根→实例表 快/慢路径；本地签名 URL 60s 窗口对齐 | perf_contract |
| 内存预算 | GD 解码前 `decodeWouldExceedMemory()`；转码分阶段峰值（解码 / 编码 / EXIF 旋转取最大）+25%+16MiB；OOM 不可捕获 → 重型端点必须 `jsonFatalGuard` | memory_guard / convert_contract |

## 4. 维护工作流（Doubao 接管后固定执行）

1. **改前**：先跑基线 harness（23 项，`node .dsh/*.js`），明确改动触及的契约。
2. **改中**：多编辑逐条写盘 + 立即 grep 验证；PHP 改动跑 `php_undef_check.js`；JS 改动跑 pre-commit 六件套；含正则的反斜杠代码用文件工具写（heredoc 不可靠）。
3. **改后**：跑受影响契约 + 全量 `xref_audit` / `audit_data_security` / `sdk_integrity_check`；大改动前跑深度审计四件套。
4. **发版**：只有用户明确「发正式版」才发（release.py 内置 `--stable` 门禁）；迭代 zip 走 `tools/release.py <ver>`，命名 `MoeRNG-v{X.Y.Z(-beta.N)}-{YYYYMMDD-HHMMSS}.zip` 归档 `releases/`（按 mtime 选取）；commit 后**立即 push main**（先 `unset` 代理变量，用 `http://127.0.0.1:7897`）。
5. **交付**：release zip（排除 config/ public/uploads/ releases/ .workbuddy/ debug_session.php + nul）+ 改动核心文件 + 部署纪律（重启 PHP-FPM 清 OPcache、跑 doctor、验证后删 doctor.php）。

## 5. 硬纪律速查（详见 MEMORY.md）

- **新增一列必须过四关**：① schema.sql ② 自迁移清单（Application + SettingController 健康检查）③ 模型 `$fillable` ④ hydrate 语义 —— 改后必跑 `model_fillable_audit.js`。
- **error 级审计才算数**：warn 级检查等于不存在；「源码里有这段」≠「功能是对的」，关键路径断言行为与相对顺序。
- **设置项必须真实接线**（读取助手 + 使用点 + 契约断言三处同步）；「待补全」判据单一来源（`thumbsIncompleteSql()`）。
- **push 铁律**：迭代 commit 后立即 push；push 前确认代理端口可用；push 成功后才允许 reset。
- **SDK 完整性**：`src/sdk/` 不受 git 跟踪，只能从 releases zip 恢复；改后必跑 `sdk_integrity_check.js`（基线文件数 aws397/cos264/obs28/oss547/qiniu30/upyun10）。

## 6. 已知缺口与待办（接管时快照，2026-09-23）

| 级别 | 事项 | 证据 |
|---|---|---|
| P1 | `thumb_bytes` + `processing_state` 两列**不在** Application 自动迁移列清单（`ensureImageColumns`），`SCHEMA_VERSION` 仍 `2026-09-11`；schema.sql / 健康检查修复清单 / `$fillable` 均已有 → **存量覆盖部署后不自动补列**，processQueue / backfillThumbs / 定向重试 / doctor 缩略图检查会失效（需手动去健康检查修复）。`audit_data_security.js` 的识别列清单同样未跟上（报绿但漏检） | Application.php ensureImageColumns；commit 65aa0f5 未含 Application.php |
| P2 | CHANGELOG.md 停在 1.3.1-beta.1，缺 1.4.0 正式版与 1.5.0 线条目 | CHANGELOG.md git log |
| P3 | 分类页两个死按钮（cat-collapse-all / cat-expand-all，无 JS 接线）待拍板 | docs/decisions/OPEN-DECISIONS.md |
| P4 | 死 API（StorageInterface::configFields/name、Controller::isPost、Request::isPost、StorageProfile::defaultDriver）；ImageController 2897 行单文件过大 | docs/audit/2026-09-21-deep-audit.md §三 |
| P5 | 开发机无 PHP CLI → 一切 PHP 行为验证靠 Node/Python 工具链（既有实践，延续） | 本机 php 不存在 |

## 7. 决策与文档位置

- 长期约束：`docs/decisions/ADR-00N-<slug>.md`；悬而未决：`docs/decisions/OPEN-DECISIONS.md`（`docs/decisions/` 不进部署包）。
- 部署文档：`src/docs/BT-DEPLOY.md`；存储布局：`src/docs/STORAGE-LAYOUT.md`（第 15 章为缩略图元数据）。
- 迭代记录：CHANGELOG.md + git commits + `.dsh/memory/`（MEMORY.md 为长期记忆，按日追加）。
