# 存储目录结构（v2 资产中心方案 · 唯一布局）

> **状态：唯一方案（v2.0.0-beta.1 / 2026-09-28 定案）**
>
> - **布局**：资产为中心 —— `{yyyy}/{mm}/{uuid}/original.{ext}` + 同目录
>   `thumb-{sm|md|lg}.webp`，无 `thumbs/` 前缀树、无 md 特例。
> - **本地媒体根**：`storage/uploads`（web 根之外），`/files` 短时签名端点读取；
>   `public/uploads` 仅保留品牌 logo（站点静态资源）。
> - **存储凭据**：`storage_profiles` 是唯一配置来源。
> - **取消兼容与迁移**：v1 布局、旧根、旧凭据的兼容层与迁移工具（`migrate-layout`、
>   本地根自动迁移、旧凭据迁移、旧布局清理）已整体移除。**升级前仍在
>   `public/uploads` 的历史文件需先手工迁至 `storage/uploads`**，升级后不再可经
>   `/files` 读取；v1 布局行若重新入队处理，缩略图按 v2 规则生成并覆盖登记。
>
> 关联代码：`Image::assetParts()/thumbKey()/newAssetPath()/decodeThumbMap()`、
> `ImageController::upload()/makeThumbnails()/convertOriginals()`、
> `settings.php`（清理暂存 / 转 WebP 面板）、`app.js::runStorageCleanup()`。
> 契约测试：`.dsh/layout_contract_test.js`（125 项）、`.dsh/thumbs_contract_test.js`、
> `.dsh/convert_contract_test.js`、`.dsh/design_contract_test.js`。

## 1. 对象键（唯一规则）

```
{yyyy}/{mm}/{uuid}/original.{ext}   ← 原图（保留原扩展名）
{yyyy}/{mm}/{uuid}/thumb-sm.webp    ← sm 320（列表/网格）
{yyyy}/{mm}/{uuid}/thumb-md.webp    ← md 640（卡片/预览；= thumb_path 列）
{yyyy}/{mm}/{uuid}/thumb-lg.webp    ← lg 1280（灯箱/大图）
```

- 一个资产 = 一个前缀，原图与全部派生物同目录 —— 删除/统计/清点都是前缀操作。
- 档位规则一致：`thumb-{size}.webp`，无 md 特例。
- 键生成收归单一来源：`Image::assetParts()`（只认 v2 形态，无法识别返回 null）、
  `Image::thumbKey()`（null 时抛 `RuntimeException`）、`Image::newAssetPath()`。
- 扩展方向：`avif-{size}.webp`、`blurhash.txt`、`og.jpg` 等同目录新增即可。
- 本地存储同构，落在存储实例 `path` 下（默认 `storage/uploads`）。
- 临时中转：`storage/incoming/{yyyy}/{mm}/{uuid}.{ext}`，处理成功即删。
- 数据库：`images.path` = 原图相对路径；`images.thumbs` = `{"ok":1,"sm":…,"md":…,"lg":…}`
  键映射（权威登记）；`thumb_path` = md 键（列级冗余回退，`decodeThumbMap()` 兜底）。

## 2. 读取路径

- 图片 URL 走 `/files?p=…&e=…&s=…` 短时签名端点（`SignedUrl`，HMAC）。
- `/files` 快路径：先试**默认根 `storage/uploads`**（**零查询**），未命中才查存储实例表
  （`profileRoots`，请求内行缓存）—— 常规图片请求完全不碰数据库。
- 每个候选根都做 realpath 越权校验（**带分隔符**比较，`uploads-evil` 之类不会误放行）。
- 本地签名 URL 按 60s 窗口对齐（`SignedUrl::URL_WINDOW`）：同一窗口内同一路径
  URL 完全一致，浏览器缓存生效。
- 云端对象存储走预签名直链，签名含签发时间 → **必须绑 CDN/自定义源站域名**才有
  稳定 URL（`doctor.php`「云端图片 URL 稳定性」检查会告知当前状态）。

## 3. 本地媒体根（storage/uploads）

| 项 | 现状 |
|---|---|
| 媒体根 | `storage/uploads`（web 根之外；伪静态 deny `storage/`，签名机制生效） |
| 品牌 logo | `public/uploads/logo`（站点静态资源，直读 + 长缓存） |
| 文件 URL | `/files?p=…&e=…&s=…`（短时签名） |
| 静态直读 | 不可以（`storage/` 已 deny） |

- 安装器默认写入 `storage/uploads`；`doctor.php` 校验「可写目录 + Storage layout
  constant + 媒体根在 web 根之外」。
- `BackupService` 备份收拢：`uploads/`（媒体根）+ `branding/`（logo）。
- `.gitignore`：`storage/uploads`、`storage/incoming`。
- **升级注意（v2.0.0-beta.1）**：`public/uploads` 历史根不再作为 `/files` 回退，
  升级前需把历史媒体手工移至 `storage/uploads`（保持 `{yyyy}/{mm}/{uuid}/…` 目录形态）。

## 4. 清理工具（cleanup-storage）

「系统设置 → 图片与存储 → 清理存储残留」（`POST /admin/images/cleanup-storage`，
**默认干跑**，`apply` 才删除）—— v2.0.0 起**只清理暂存垃圾**：

| 清理对象 | 判定依据 |
|---|---|
| `storage/incoming/**` 里不再属于「在办」行的临时文件 | 无对应记录，或该行状态不是 pending/processing/failed |

响应：`success/mode/staging/remaining`（干跑 + 执行由前端按批携带 `from` 推进，
游标持久化于 `settings.storage_cleanup_cursor`，只在 apply 时推进）。

### 4.1 无法清理的部分（如实说明）

`StorageInterface` **没有 LIST 能力**，无法枚举桶内对象，因此：

- **记录已被删除的孤立对象**（例如「清空队列」失败项的原图已上传到最终存储）
  **无法被发现** —— 需到对象存储控制台按前缀人工清理；
- 云端对象的"孤儿检测"同理不可行。

工具与 UI 都明确写出这一点，不制造"已清干净"的错觉。

## 5. 原图统一转 WebP

- **上传时自动转**（「原图转 WebP」，默认开，质量默认 90）；
- **历史原图批量补转**（「转换历史原图为 WebP」，干跑优先，可中断续跑）。

转换发生在**计算哈希之前** —— 记录的 `file_hash` / `file_sha256` 描述**实际存进存储的
字节**。

**键变化**：只换扩展名、布局不动 —— `{dir}/original.{ext}` → `{dir}/original.webp`；
缩略图键与扩展名无关（同目录固定名 `thumb-{size}.webp`），**无需搬迁**。

### 5.1 明确"不转"的情形（每条都有理由，不为了统一而牺牲正确性）

| 情形 | 原因 |
|---|---|
| GIF | GD 只能取第一帧，转了会丢动画 |
| SVG | 矢量图，位图化会失真 |
| JPEG 且 PHP 读不到 EXIF | 浏览器会按 EXIF 旋转 JPEG、不会旋转 WebP → 会把手机照片转歪 |
| 解码会超出可用内存 | 复用缩略图的同一内存预检，避免不可捕获的 OOM |
| 编码结果不比原文件小 | "优化"不能反而让站点更慢（小图/已优化图很常见） |
| 解码/编码失败 | 宁可存原图，也不因一次编码失败丢掉用户上传 |

### 5.2 流程与护栏（任一步失败即回滚该行）

```
取字节 → 转码 → 上传新键 → 校验新对象存在 → 更新记录（path/mime/size/双哈希）→ 删除旧对象
```

- 只处理 `process_status='done'` 且 `path` 非 `.webp` 的行；源格式须在可转白名单内；
- **先校验新对象存在，再改库** —— 任何时刻记录都指向存在的对象；
- 改库失败 → **删掉刚上传的新对象**回滚（旧对象与记录原封不动）；
- 删旧失败只计 `orphan_risk`（新对象已就位，服务不受影响）；
- 游标 `settings.original_convert_cursor` **只在 apply 时推进**；
- 云端每行都要下载 + 上传 —— 建议先干跑看数量再分批执行。

### 5.3 干跑语义

干跑由前端按批携带 `from` **推进到全表结束**（不写任何状态），是零 I/O 的候选筛查
（不下载、不解码、不写文件，只判「格式可转 + 键名可改写」）；"是否真的更小"只能在
执行时逐张判定，执行后「已转」可能少于候选数，这是如实报告而不是 bug。

## 6. 重型端点的致命错误与内存预算

### 6.1 症状：点「干跑检查」得到「服务器返回空响应（HTTP 500）」

PHP 致命错误（不可捕获）的典型长相 —— 响应体为空，因为控制器只在整个循环结束后才
输出 JSON。

| 层面 | 问题 |
|---|---|
| 端点接线 | 6 个重型端点里只有 `process-queue` / `backfill-thumbs` 装了致命错误守卫 → 转换、清理、上传、哈希回填全是"裸奔" |
| 预算口径 | 原图转码沿用缩略图的内存预检（`w×h×4×1.25 + 32 MiB`），少算了 `file_get_contents()` 的整个原始字节，以及 EXIF 旋转时 `imagerotate` 必然新建的第二块画布（≈ 再 +`w×h×4`） |

### 6.2 修法

1. **所有重型端点统一装守卫**（`jsonFatalGuard`）：致命错误转 JSON，提升内存与时间上限；
   响应带 `memory_limit`、`peak_mb`、出错文件行号。
2. **原图转码用独立预算**（`originalConvertBudget`），按**分阶段的峰值**取最大值估算：

   | 阶段 | 占用 |
   |---|---|
   | ① 解码 | `file_get_contents` 的整个文件字节 + 一块真彩画布 `w×h×4` |
   | ② 编码（`imagewebp`） | 画布 + 编码器同级缓冲 ≈ 再一块 `w×h×4` |
   | ③ EXIF 旋转（`imagerotate`） | 新旧两块画布并存 ≈ `2 × w×h×4` |

   再乘 25% 缓冲 + 16 MiB 基线，要求 ≤ 可用内存 × 0.85。
   **刻意不套用 `thumb_max_pixels`**（那是缩略图成本上限，限制原图会误杀合法大图）。
   超预算 → **跳过并如实计数**，绝不继续解码/编码。

   > 教训：编码器不是"几乎不占内存"。libwebp 编码目标与原图同尺寸时至少要再一块
   > 同级缓冲。上一版预算只留 25%，73 MP 那张被"放行"到 `imagewebp` 才炸 ——
   > 现在同一张会在解码前就被跳过并计入「超出内存预算（跳过）」。
   > **不要照抄阈值，照抄公式**（门槛随 memory_limit、源文件大小、EXIF 旋转而变）。

3. **转码绝不改写源文件**：`imagewebp` 编码到**同目录临时文件**，只有判定"确实更小"
   之后才 `rename` 覆盖；批量路径用私有副本，干跑严格只读、中途失败不会留下
   "记录说 .jpg、实际是 WebP"的错配状态。

## 7. 图片处理状态总控列（processing_state）

### 7.1 为什么需要它

`size()` 读不到对象长度时，那一档的字节数怎么处理？「失败也写标记」会永久放弃该行；
「失败不写标记」会让行留在集合里每轮被选中 → 前端无限循环。根因是判据只能表达
「未处理」，无法表达「处理过但失败」—— 于是引入状态列，失败行**离开队列**却
**仍可被定向重试**。

### 7.2 列形态

`images.processing_state JSON NULL`，内容形如 `{"thumb_meta":"partial"}`。键是处理项名，
将来新增处理项只需加键，不必再加列。**键缺失 = 从未处理**（视作 `pending`）⇒ 加列时
存量行天然被视作「还没补过」，不需要停机回填。

★ 与 `process_status` 的分工：`process_status` 是上传处理**流水线**状态（ENUM，参与
前台可见性过滤）；`processing_state` 是各处理项的**结果**状态（JSON，不参与过滤）。

### 7.3 状态取值与推导（`Image::deriveThumbMeta()` 唯一来源）

| 状态 | 含义 | 定向重试？ |
|---|---|---|
| `pending` | 尚未处理（键缺失时的默认值） | —（由「补全」处理） |
| `ok` | `thumbs` 里列出的每个档位都有实测字节数 | 否 |
| `partial` | 部分档位拿不到对象长度 | **是** |
| `failed` | 该有结果却一个都没拿到 | **是** |
| `skipped` | 本来就不需要处理（源图小于所有档位 / 不可解码） | 否 |

推导顺序：`thumbs` NULL/空 → `pending`；解析不出任何档位 → `skipped`；
每档都有字节 → `ok`；部分有 → `partial`；一档都没有 → `failed`。
判据是「`thumbs` 里列出的」档位 —— 上传时某档失败的行该档不在 `thumbs` 里，仍算 `ok`。

### 7.4 两个入口（状态驱动，带 JSON_VALID 防护）

| 入口 | 选择的行 |
|---|---|
| 「补全缩略图」（`scope=pending`） | `thumb_meta = pending`（含 NULL / 缺键） |
| 「重试元数据补全」（`scope=retry`） | `thumb_meta ∈ {partial, failed}` |

retry 用扫描游标推进（失败行重试后状态仍是 `failed`，按判据重选会取到同一批）；
retry 跳过 GD 与总开关守卫（只向存储询问对象长度，不解码、不生成）。

### 7.5 零网络快速路径

处理一行前先做纯 DB 推导：若 `thumbs` 与 `thumb_bytes` 都已完整，说明状态本该是 `ok`、
只是还没被写下来 → 直接写状态即可，**不下载、不解码、不请求存储**（存量行一次补齐，
也顺带自愈状态写着 `partial` 但数据已完整的历史不一致）。

### 7.6 硬失败刻意不写状态

「原图取不到 / 存储不可达 / 无可用实例」这类**硬失败**保持 `pending`（下次仍会被
「补全」选中）；「读到长度失败」写 `failed`（离开队列，等环境恢复后人工重试）。

> 相关契约测试：`.dsh/thumbs_contract_test.js` §9、`.dsh/model_fillable_audit.js`。
