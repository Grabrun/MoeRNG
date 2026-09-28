# 深度审计报告：v2.0.0-beta.1 兼容层拆除「全站无残留」验证

- 审计日期：2026-09-28
- 基线 commit：`c888590`（`refactor(storage): v2.0.0-beta.1 — cancel all legacy compat & migration`）
- 审计目标：验证取消兼容与迁移的拆除（旧布局 / 旧根 / 旧凭据 / 迁移工具 / 死 API / 旧版本标识）在全仓库**零功能性残留**；修复发现的残留并重新归档。
- 审计方式：全量符号扫描（33 符号 → 365 命中逐条分类）+ src 过滤扫描 + 死设置键扫描 + 编码扫描 + 静态 harness + 全量合约回归 + 归档校验。

---

## 1. 基线确认

| 项 | 结果 |
|---|---|
| git HEAD | `c888590`（与远程 main `c888590d…225d` 一致） |
| APP_VERSION | `2.0.0-beta.1`（src/bootstrap.php:26） |
| SCHEMA_VERSION | `2026-09-28`（Application.php 常量） |
| 工作区 | 仅未跟踪 `design/_shots/`（已 .gitignore，目录级规则生效） |
| 历史归档 | `MoeRNG-v1.5.0-beta.1-20260922-131456.zip`、`…-20260923-134946.zip`（保留历史版本） |

## 2. 兼容层残留全量扫描（33 符号 / 365 处命中）

分类口径：**功能性残留**（代码路径可执行/被读取）vs **文档性提及**（注释、CHANGELOG、手术脚本、审计记录——工程史，合理保留）。

### 2.1 功能性残留（本次发现 1 处，已修复）

| 位置 | 残留 | 处置 |
|---|---|---|
| `src/app/Core/Application.php:356-357`（ensureImageColumns 的 backfill） | 读取已取消的旧全局设置键 `settings.storage_driver` / `settings.storage_s3_provider`，且回退值硬编码 `'local'` / `'cos'` —— v2.0.0 语义下会向历史空列行写入错误 driver/provider | **已修复**：改为从 `StorageProfile::defaultProfile()` 推导（storage_profiles 为唯一来源），旧键读取彻底移除 |

### 2.2 核实为非残留的 src 命中

| 位置 | 判定 |
|---|---|
| `InstallController.php:234/258/273` `$insertProfile` | PDO prepare 语句局部变量（新装建默认 profile），非 Application 迁移函数 |
| `ImageController.php:1779/1811/1830/1856/1872` `listFilesRecursive` / `pruneEmptyDirs` | 通用递归工具，被**新版 cleanupStorage（仅暂存清理）**复用，无旧语义分支，保留正确 |
| `helpers.php` `dd()` | **死调试函数（全库零调用）→ 已删除**（另见 §4） |
| `CredentialCipher.php` `legacy` | ① decrypt 对非密封 blob 透传原值：防御性格式容错（新写入必加密，透传只命中历史明文）；② loadKey 的 `credential_key.php` 拉入统一 config：**一次性幂等密钥文件收口**，执行后删除旧文件，不构成持续兼容。二者保留（删除会导致升级站点凭据全灭），报告备案 |
| `doctor.php` "Legacy cleanup" | 通用运维工具（孤儿设置行 / `nul` 文件 / 空目录清理），非兼容层功能 |
| src 内 `1.5.0-beta.1` / `v1.5.0`（51+92 命中） | 全部为**注释中的版本历史标注**（`v1.5.0-beta.3: …` 等），无功能性字符串（唯一功能性命中为 `queue.php:26` 的 PHP 注释，非字符串） |
| `settings.migration_last_error` | schema 自迁移的错误上报机制（SCHEMA_VERSION 门禁的一部分），是产品核心机制，非旧数据兼容迁移 |

### 2.3 文档性提及（合理保留，零功能）

- `CHANGELOG.md`、`docs/PLAN-CANCEL-COMPAT-2026-09-28.md`、`docs/audit/2026-09-21/23-*.md`、`docs/DEV-PLAN.md`、`docs/decisions/OPEN-DECISIONS.md`：拆除记录、验收标准、历史决策——保留即证据。
- `docs/scripts/strip_*.py`、`patch_*.py`：手术脚本（已随 c888590 提交）——保留即可审计的拆除轨迹。
- `src/sdk/**`（AWS SDK 等第三方）内 `legacy` / `migrate`：**第三方 SDK 自身代码**，与项目兼容层无关。

## 3. 死设置键 / 悬空引用

- 代码级 `Config::get('settings.*')` 读取点扫描：全部有写入来源（admin 设置面板通用保存）；无只读不写的孤儿键（`settings.storage_driver` 唯一功能读取点即 §2.1 已修复处，doctor.php:299 为注释）。
- `ref_check.js`：72 个自研 JS 调用全部有定义，**无悬空引用**。
- `xref_audit.js`：58 处视图 action/href ↔ 路由、156 处 getElementById、366 处 class ↔ CSS，**0 提示**。
- `php_undef_check.js`：57 个 PHP 文件 AST 级，**0 未定义变量**。
- `verify_autoload.py`：46 个 `App\` 符号全部可解析。

## 4. 安全面复核

- `audit_data_security.js`：非安装类 POST 39/43 有 CSRF（其余为豁免类）；25 个视图无未转义用户可控字段输出（XSS 面干净）。
- SQL 插值白名单确认：`ImageController.php:1035` `$targetCond` 来自 `Image::thumbMetaPendingSql()/thumbMetaRetryableSql()`（模型常量拼装）；`$batchSize` `(int)` + 1–10 钳制；`$fromSql` `(int)` 强制——**无注入面**。
- 真实凭据形态扫描（归档内）：`sk-…` / `AKIA…` / `access_key` 赋值形态 **0 命中**。
- git 跟踪 139 文件：`src/config/app.php` 为默认值模板（`installed=false`），无密钥。

## 5. 调试 / 编码 / 临时文件残留

- **调试残留**：`helpers.php` 的 `dd()` 死函数（零调用）→ 已删除；src 应用代码无 `var_dump/print_r/console.log/error_log`（其余命中均在 `reference/` 第三方 SDK 示例/测试与 `tools/archive` 一次性工具内，非交付物）。
- **编码**：交付物（git 跟踪 + 归档）零 BOM、零非 UTF-8、零零字节文件。`.dsh/_appjs_head.js`（BOM）与 `reference/**`（SDK 原版副本）均非交付物。
- **临时文件**：`design/_shots/` 已 .gitignore；无 `thumbs.db` / `.tmp` 进入仓库。

## 6. 全量回归（本轮修复后）

| 契约 | 结果 |
|---|---|
| layout_contract_test | 125/125 ✓ |
| thumbs_contract_test | 118/118 ✓ |
| design_contract_test | 63/63 ✓ |
| perf_contract_test | 74/74 ✓ |
| queue_contract_test | 143/143 ✓ |
| api_contract_test | 67/67 ✓ |
| convert_contract_test | 71/71 ✓ |
| memory_guard_test | 46/46 ✓ |
| hero_stats_contract_test | 35/35 ✓ |
| click_matrix / page_matrix / scope / click | 全部 ✓ |
| syntax_check | **2912/2912** ✓ |
| sdk_integrity / model_fillable / audit_data_security / xref / ref_check / php_undef / verify_autoload | 全部 ✓ |

## 7. 修复与再归档

| 项 | 结果 |
|---|---|
| 修复 1 | `Application.php` backfill 改为默认 profile 推导（旧键读取移除） |
| 修复 2 | `helpers.php` 删除 `dd()` 死调试函数 |
| 新归档 | `releases/MoeRNG-v2.0.0-beta.1-20260928-141050.zip`（1388 文件） |
| 归档校验 | zip 完整性 OK；sdk/aws 397 与基线一致；真实凭据扫描干净；兼容层功能性符号（storage_s3_provider 读取 / migrateLayout / runLocalRootMigration / migrateProviderCredentials / cleanupLegacyObjects / LEGACY_REL_DIR）**在 zip 内全部零命中** |
| 新增 config/ | `config/app.php`（默认模板）+ `config/.htaccess`（Deny from all）+ `public/uploads/.htaccess`——安全收口 |

## 8. 审计结论

**v2.0.0-beta.1 兼容层拆除在全站（git 跟踪 + 发布归档）已实现零功能性残留**：

- 旧布局 / 旧根（LEGACY_REL_DIR、legacyUploadDir、LAYOUT_V1、旧 thumbs 前缀）→ 零功能命中；
- 迁移链（migrateLayout、runLocalRootMigration、migrateProviderCredentials、migrateLegacyToProfiles、insertProfile 方法、hasDefaultProfile、LOCAL_ROOT_LAYOUT、local_root_*）→ 零功能命中；
- 死 API（configFields、name()、isPost、defaultDriver）→ 零功能命中；
- 旧凭据键（storage_s3_*、storage_providers）→ 唯一读取点已修复，现零功能命中；
- 旧版本标识 → src 内仅注释性历史标注；
- 调试残留 → `dd()` 已删，零调用残留；
- 文档/脚本中的提及均为拆除证据与工程史，属合理保留。

**保留项（备案，非残留）**：`CredentialCipher` 的密钥文件一次性收口迁移与 decrypt 明文透传（数据连续性，删除会导致升级站点凭据全灭）；doctor 的通用遗留清理（运维工具）。如需按更严口径移除，需先明确升级数据策略。

---

*扫描脚本：`docs/scripts/audit_scan_compat.py` / `audit_round2.py` / `audit_settings_keys.py` / `audit_round4.py` / `build_release_v200.py`（随本报告提交）*
