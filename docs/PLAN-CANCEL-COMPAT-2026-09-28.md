# M2.5 取消兼容与迁移（2026-09-28 决策）

> 用户指令（2026-09-28）：「现在取消对之前的所有兼容与迁移，使用新版本方案」
>
> 推翻 v1.5.0-beta.1 以来「新旧布局并存、迁移可选、读取端零改动」的兼容策略：
> 强制统一资产中心方案（v2 布局 + 本地媒体根 `storage/uploads`），
> 拆除全部旧布局 / 旧根 / 旧凭据的兼容层与迁移工具。

## 1. 决策与数据影响（升级前必读）

| 项 | 旧策略（v1.5.0-beta.1） | 新策略（本版本起） | 存量数据影响 |
| --- | --- | --- | --- |
| 存储布局 | v1 `{Y}/{m}/{uuid}.{ext}` + `thumbs/` 与 v2 `{Y}/{m}/{uuid}/original.{ext}` + `thumb-{size}.webp` 并存，迁移可选 | **只认 v2**；生成端键推导唯一规则 | 读取端不受影响（`thumbs` JSON / `thumb_path` 列是权威登记，与布局无关）；v1 行若重新入队处理，缩略图将按 v2 规则生成并覆盖登记 |
| 本地媒体根 | `public/uploads`（旧）与 `storage/uploads`（新）并存，首请求自动迁根 | **只认 `storage/uploads`**（web 根之外）；`/files` 签名端点不再回退旧根 | 升级前仍留在 `public/uploads` 的文件在升级后不再可经 `/files` 读取；**需在升级前完成迁根**（文件可移至 `storage/uploads`） |
| 存储凭据 | 旧 settings `storage_s3_*` / `storage_providers` 键会在迁移时转入 `storage_profiles` | `storage_profiles` 是唯一来源，不再读取/迁移任何旧键 | 无影响（旧键从未被读取路径消费）；doctor 不再检查孤儿键 |
| 布局迁移工具 | `POST /admin/images/migrate-layout` + 设置页「存储结构与迁移」面板 | **整体移除** | 升级前未迁移的 v1 行保留（读取可用），但不再有工具可批量迁移 |
| 清理工具 | `cleanupStorage` 含「旧布局对象」「历史根无引用文件」两类 | 只保留「暂存垃圾」（`storage/incoming`，新方案仍需） | 无影响 |

版本号：**2.0.0-beta.1**（取消兼容属破坏性变更，按项目约定 MAJOR+1+beta.1）。

## 2. 拆除范围（文件 → 符号）

### A. 布局解析收口（Image.php）
- `LAYOUT_V1` 常量删除，`LAYOUT_V2` 保留；`THUMB_SIZES` / `thumbKey` 注释更新
- `assetParts()`：只认 v2 形态，删除 v1 fallback（返回 null 表示无法识别）
- `thumbKey()`：删除旧分支（md 无尺寸段 / `thumbs/{size}/`），一律 `{dir}/thumb-{size}.webp`
- `decodeThumbMap()` 的 `thumb_path` 兜底保留（列级冗余回退，无布局耦合）

### B. 本地根收口（LocalDriver / FileController）
- `LocalDriver`：删 `LEGACY_REL_DIR`、`legacyUploadDir()`、`configFields()`、`name()`（后二者属死 API，见 E）
- `FileController::show()`：回退链去掉 `legacyUploadDir()`，仅 [默认根] + [存储实例自定义根]

### C. 迁移链拆除（Application.php）
- 删除整条本地根迁移链：`runLocalRootMigration` / `migrateLocalMediaRoot` / `rollbackMedia` / `moveMediaEntry` / `rewriteLocalProfilePaths` / `revertLocalProfilePaths`
- 删除 `LOCAL_ROOT_LAYOUT` 常量与 bootstrap 门禁（:102-104）
- `runStorageMigration()`：删除 `migrateProviderCredentials()` / `migrateLegacyToProfiles()` 调用
- 删除 `migrateProviderCredentials()` / `migrateLegacyToProfiles()` / `insertProfile()` / `hasDefaultProfile()`（后者仅被迁移函数使用）
- 保留：`ensureStorageProfileTable` / `ensureImageColumns` / `ensureImageProfileColumn` 等（DB 结构自迁移是部署机制，非兼容层）

### D. 迁移工具与清理分支（ImageController）
- 删除 `migrateLayout()` + `copyObjectWithinDriver()`（:1405-1660 附近）
- `cleanupStorage`：删除「旧布局对象」「历史根无引用文件」两类分支与相关统计（`cleanupLegacyObjects` / `cleanupLegacyRootFiles` / `listFilesRecursive` / `pruneEmptyDirs` 视引用情况删除）；只保留 `storage/incoming` 暂存清理
- `cleanupDiagnostics`：删旧布局计数与 `refs_thumbs_prefix` 判定
- `convertOriginals`：清理旧布局分支说明（转 WebP 本身保留）
- admin.php：删 `POST /images/migrate-layout` 路由；`cleanup-storage` 保留（语义收窄）

### E. 死 API 收口
- `StorageInterface`：删 `configFields()` / `name()` 声明
- 8 个驱动（AwsSdkDriver / CosSdkDriver / LocalDriver / ObsSdkDriver / OssSdkDriver / QiniuSdkDriver / S3Driver / UpyunSdkDriver）：删对应实现（无调用方，已 grep 确认）
- `Controller::isPost()`、`Request::isPost()`：删除（无调用方）
- `StorageProfile::defaultDriver()`：删除（无调用方，`defaultProfile()` 保留）

### F. 前端与检查（settings.php / app.js / doctor.php）
- settings.php：删「存储结构与迁移」面板（layout-* 共 6 id）；「清理存储残留」面板文案与结构收窄（cleanup-* 保留，但改述为只清暂存）；「转换历史原图为 WebP」面板保留
- app.js：删 `runLayoutMigration` / `initLayoutMigration` 及其接线；`runStorageCleanup` / `sumCleanupResponse` 适配收窄后的响应；文案同步
- admin.php 路由（见 D）
- doctor.php：删本地根迁移检查段（:404-420）、`local_root_error` 相关（:600）、「Settings orphan keys」检查（:729-769）；存储检查只保留 profile 语义

### G. 契约 harness 同步
- `layout_contract_test.js`：删除旧布局/迁移断言（旧 md 规则红线、迁移三阶段、§13/13b 清理语义）—— 转为断言「v1 形态不可识别 / thumbKey 唯一 v2 规则」
- `thumbs_contract_test.js`：删旧布局键断言
- `click_matrix_test.js`：删除 layout-check/layout-migrate/layout-* 登记；cleanup 按钮语义更新
- `design_contract_test.js`：settings 页 JS id 集更新（去掉 layout-*）
- 其余（audit_data_security / model_fillable_audit / ref_check / syntax_check / verify_autoload / p0_scan / perf_contract / convert_contract）：跑全量看报什么，逐个同步

### H. 文档
- `src/docs/STORAGE-LAYOUT.md`：重写为「仅 v2 布局」单一方案，删除兼容/迁移章节
- `docs/MAINTAINER-GUIDE.md` / `BT-DEPLOY.md`（如有迁移步骤）：同步
- `CHANGELOG.md`：记录 2.0.0-beta.1 破坏性变更
- `docs/audit/2026-09-23-deep-audit.md`：死 API 项标记已收口

## 3. 验收标准
1. 全量 harness 通过（M1 后 23 项基线 + 本轮更新）
2. 语法检查通过（php-parser node 脚本）
3. 全库无 `LEGACY_REL_DIR` / `legacyUploadDir` / `migrateLayout` / `runLocalRootMigration` / `migrateProviderCredentials` / `configFields` / `::isPost` / `defaultDriver` 残留引用
4. 版本号 2.0.0-beta.1；归档 zip；commit + push

## 4. 执行顺序
A（Image）→ B（LocalDriver/FileController）→ E（死 API）→ C（Application）→ D（ImageController）→ F（前端/doctor）→ G（harness）→ H（文档）→ 回归 → 归档 → push
