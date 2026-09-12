# 存储目录结构统一方案（提案 v1）

> 状态：**待评审**（未实施）。评审通过后按 SemVer 作为 1.4.0 线内的兼容功能实施。
> 关联代码：`Image::thumbKey()`、`ImageController::upload()`、`ImageController::makeThumbnails()`、
> `LocalDriver`、`SignedUrl`、`FileController`。

## 1. 现状

对象键（= 相对路径，bucket 根下无前缀）：

```
{Y}/{m}/{uuid}.{ext}                    ← 原图
thumbs/{Y}/{m}/{uuid}.webp              ← md 640（无尺寸段的"历史路径"）
thumbs/sm/{Y}/{m}/{uuid}.webp           ← sm 320
thumbs/lg/{Y}/{m}/{uuid}.webp           ← lg 1280
```

- 本地存储同构，落在存储实例 `path` 下（留空默认项目根 `public/uploads`）
- 临时中转：`storage/incoming/{Y}/{m}/{uuid}.{ext}`，处理成功即删
- 数据库：`images.path` = 原图相对路径；`images.thumbs` = `{"ok":1,"sm":…,"md":…,"lg":…}` 键映射

## 2. 现状的七个问题

| # | 问题 | 后果 |
|---|---|---|
| P1 | 原图与派生物**分属两棵树**（根 vs `thumbs/`） | 删除/迁移/清点一个资产要动两处，容易漏 → 孤儿对象 |
| P2 | `md` 兜底路径**带特例**（无尺寸段），`sm/lg` 带尺寸段 | 代码里必须写 `if ($size === 'md')` 分支；新档位越多，特例越多 |
| P3 | bucket 根被 `thumbs/` 占一层 | 未来派生物（AVIF、blurhash、水印版、OG 卡）继续堆在根，语义退化 |
| P4 | 无**世代/版本**维度 | 改缩略图尺寸或质量后无法重生成而不污染旧对象；无法灰度/回滚 |
| P5 | 无**公共前缀** | 同一 bucket 复用多环境/多站点时键空间混放（现在只能靠分 bucket 隔离） |
| P6 | 本地默认根在 `public/uploads`（**web 根之下**） | 目录语义混淆：web 可达目录同时充当资产库；与 `storage/incoming` 对称性差 |
| P7 | 关联靠**隐式同名推导**（`x.webp` ↔ `x.png` → `thumbs/…/x.webp`） | 键规则一旦变更，历史对象无法自解释；`thumbs` JSON 是唯一权威登记 |

## 3. 设计目标（验收标准）

1. **资产级操作**：一个资产的全部对象在同一前缀下 → 删除/统计/迁移都是前缀操作
2. **确定性推导**：键由 `(资产, 变体, 档位, 世代)` 唯一确定，**不需要 LIST** 即可定位
3. **规则一致**：所有档位同一套规则，`md` 特例消失
4. **平滑演进**：新对象走新规则，老对象原地可读；提供 dry-run 迁移工具
5. **可选前缀**：支持环境/站点隔离，默认空（向后兼容）
6. **不牺牲安全属性**：保持本地 HMAC 签名、web 不可达、realpath 越权校验、deny 后缀

## 4. 候选方案

### 方案 A（推荐）：资产为中心

```
{prefix?}/{yyyy}/{mm}/{uuid}/
        original.{ext}          ← 原图（保留原扩展名）
        thumb-sm.webp
        thumb-md.webp
        thumb-lg.webp
```

- 一个资产 = 一个前缀，四种对象全部在内
- 档位一致：`thumb-{size}.webp`，无特例
- 扩展方向自然：`avif-{size}.webp`、`blurhash.txt`、`og.jpg`、`watermark-{size}.webp` 都是同目录新增文件
- 极高频月份可插一级散列：`{yyyy}/{mm}/{uuid 前 2 位}/{uuid}/`（避免单前缀集中）

### 方案 B：类型优先 + 世代

```
{prefix?}/original/{yyyy}/{mm}/{uuid}.{ext}
{prefix?}/thumbs/v2/{sm|md|lg}/{yyyy}/{mm}/{uuid}.webp
```

- 类型职责清晰；`v2` 世代段让"改参数后重生成"可并存、可回滚
- 但一个资产仍分散两棵树，P1 未解；清理策略要写两套

### 方案 C：最小修补（不推荐）

只把 `md` 改成带尺寸段、统一为 `thumbs/{size}/…`。消除 P2，其余问题全部保留。

### 对比

| 维度 | A 资产为中心 | B 类型优先 | C 最小修补 |
|---|---|---|---|
| 资产级删除/统计 | ✅ 单前缀 | ⚠️ 两处 | ❌ 两处 + 特例 |
| 派生物扩展 | ✅ 同目录新增 | ⚠️ 需新顶层树 | ❌ 继续堆根 |
| 档位规则一致 | ✅ | ✅ | ✅（但破坏历史 md） |
| 世代管理 | ✅ 目录级可选 `v2/` | ✅ 顶层 `v2/` | ❌ |
| 老对象兼容 | ✅ 原地可读 | ✅ 原地可读 | ⚠️ 老 md 键失效 |
| 实现面 | 键生成 2 处 + 删除路径 | 同 A，另加两套清理 | 1 处 |
| 迁移成本 | 中（可选，可分批） | 中 | 低但留下欠债 |

## 5. 推荐方案 A 详细设计

### 5.1 键生成（单一来源）

新增 `Image::objectKey()`，取代散落拼接；`thumbKey()` 保留为**读侧兼容**别名：

```php
// 伪代码：唯一键来源
Image::objectKey(version: 'original'|'thumb':string, size: ?string, path: string, ext: ?string): string
  prefix = 存储实例前缀（默认 ''）
  base   = "{yyyy}/{mm}/{uuid}"           // 由 path 解析（yyyy/mm/uuid 均为 path 的一部分，无需查库）
  original → "{prefix}{base}/original.{ext}"
  thumb    → "{prefix}{base}/thumb-{size}.webp"
  // 世代（可选，默认不启用）："{prefix}{base}/v{gen}/thumb-{size}.webp"
```

- `{yyyy}/{mm}/{uuid}` **全部可从 `images.path` 解析**，因此新规则对历史行同样可推导（迁移工具的基础）
- 尺寸列表仍取 `Image::THUMB_SIZES`

### 5.2 数据库

**无需变更**。`path` 与 `thumbs` JSON 已存"每档实际键"，读取端（`thumbMap()` / `displayUrl()`）**零改动** —— 这是本方案可以纯增量落地、且老对象无需回填即可继续可读的根本原因。

### 5.3 本地存储

- 磁盘：`<uploadDir>/{yyyy}/{mm}/{uuid}/…`（同构）
- **建议同步把默认根从 `public/uploads` 迁到 `storage/uploads`**（web 根之外），彻底走 `/files` 签名端点；`public/uploads` 保留为只读兼容目录（老对象仍可经 `/files` 命中，因为 `uploadDir` 就是它）
- 迁移默认根需要同步：宝塔伪静态（deny 段）、`BT-DEPLOY.md`、`nginx.conf.example`、`doctor.php` 自检

### 5.4 清理与一致性

- 删除资产：按固定键逐个删（≤4 个，确定性），**无需 LIST**；删除失败进重试队列
- 可选兜底：`{prefix}{yyyy}/{mm}/{uuid}/` 前缀 LIST，兜住未来新增的派生物
- `doctor.php` 增加"结构抽样自检"：随机 20 行，校验 `path` / `thumbs` 键可访问

## 6. 兼容与迁移（分阶段，可随时停在任一步）

| 阶段 | 内容 | 回滚 |
|---|---|---|
| 0 | 落地 `objectKey()` + 契约测试；**读写仍按现行规则**（无行为变化） | 直接回退代码 |
| 1 | 新上传/新生成走新结构；老对象原位不动、继续可读（读由 DB key 驱动） | 回退代码；新对象仍可读（DB 存了键） |
| 2 | 迁移工具（默认 dry-run）：按前缀 `copy → verify（size/hash）→ delete`，分批 + 审计留痕，复用队列页驱动模式 | 迁移未 `delete` 前可中断；已删对象可用备份/回源恢复 |
| 3 | 统一完成后，`thumbKey()` 兼容别名降级为"仅读历史"（或按需删除） | — |

要点：**阶段 1 之后新旧并存是完全可用的稳态**，不强制迁移；是否迁移由你按对象规模决定。

## 7. 影响面清单（实施时的改动点）

- `src/app/Models/Image.php`：`objectKey()` 新增；`thumbKey()` 标注为读侧兼容
- `src/app/Controllers/Admin/ImageController.php`：`upload()` 的 `remotePath`、`makeThumbnails()` 的 key、`uploadThumbs()`、队列/清理路径的临时文件与对象删除
- `src/app/Storage/LocalDriver.php` + `SignedUrl` + `FileController`：键变化自动跟随（无需逻辑改动）
- `src/doctor.php`：结构自检
- `.dsh/` 契约测试：`objectKey()` 确定性/前缀/扩展名单测；队列与缩略图契约同步
- 文档：`src/docs/STORAGE-LAYOUT.md`（本文件）、`BT-DEPLOY.md`、`nginx.conf.example`

## 8. 风险

| 风险 | 缓解 |
|---|---|
| CDN 缓存/回源路径变化 | 新结构对象是新键 → 无缓存冲突；老键继续可用 |
| 迁移中断导致半迁移 | 按资产为原子单位（copy 全部成功才 delete）；记录审计 |
| `public/uploads` 迁移影响既有链接 | 老对象保留在原根；仅**新**对象落新根 |
| 键解析假设 `Y/m/uuid` 形态 | 解析失败的行**不迁移**并标记，人工处理；读路径不受影响 |

## 9. 需要你确认的决策点

1. **前缀**：是否引入 `prefix`（默认空）？——建议引入（配置项，默认空）
2. **本地默认根**：是否迁到 `storage/uploads`？——建议迁（web 根外更干净），同步改文档/伪静态
3. **世代目录 `v{n}/`**：是否现在就留？——建议**先不启用**，键生成函数预留参数位
4. **是否迁移存量**：不迁移也完全可用（新旧并存）——建议先不迁，等结构稳定后再决定
