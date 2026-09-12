# 存储目录结构统一方案（提案 v1）

> **状态：已实施（v1.5.0-beta.1 / 2026-09-12）**
>
> - **已采用（方案 A 资产为中心）**：
>   - 新上传/新生成走 `{yyyy}/{mm}/{uuid}/original.{ext}` + `{dir}/thumb-{sm|md|lg}.webp`
>   - 布局按 `images.path` 的**形态**判定（`Image::assetParts()`），因此**读取端零改动**（DB 里已存具体键），新旧布局可长期并存
>   - 键生成收归单一来源（`Image::assetParts/thumbKey/newAssetPath`），`md` 特例只保留在旧布局分支
>   - 存量迁移工具：`POST /admin/images/migrate-layout`（默认 `dry-run`；`apply` 逐资产原子，三阶段**复制校验 → 改库 → 删旧**），入口在「系统设置 → 图片与存储」
> - **暂缓（各自独立小步，不影响本方案）**：
>   - 公共前缀 `{prefix}`：需触及全部 7 个驱动与 `/files` 读取路径，风险与收益需单独评估
> - **已实施（v1.5.0-beta.1）**：本地默认根迁出 `public/` → `storage/uploads`（见第 10 节）
> - **未启用但已留位**：世代目录 `v{n}/`（键生成函数预留参数位）
>
> 关联代码：`Image::assetParts()/thumbKey()/newAssetPath()/decodeThumbMap()`、
> `ImageController::upload()/migrateLayout()/copyObjectWithinDriver()`、
> `settings.php` 迁移面板、`app.js::runLayoutMigration()`。
> 契约测试：`.dsh/layout_contract_test.js`（70 项，含三向故障注入验证）。

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

## 9. 决策点与结论（2026-09-12）

| # | 决策点 | 结论 | 说明 |
|---|---|---|---|
| 1 | 公共前缀 `{prefix}` | **暂缓** | 落在驱动层最自然（装饰器），但要同时改 7 个驱动与 `/files` 读取路径；建议作为独立小步单独评估 |
| 2 | 本地默认根迁出 `public/` | **已实施（v1.5.0-beta.1）** | 默认根 → `storage/uploads`（web 根之外），随版本自动一次性迁移（第 10 节）；同步了宝塔伪静态、`BT-DEPLOY.md`、`nginx.conf.example`、`doctor.php`、安装器、备份 |
| 3 | 世代目录 `v{n}/` | **不启用，留扩展位** | `objectKey` 侧预留形参；将来改缩略图参数需要并存时再启用 |
| 4 | 是否迁移存量 | **已实现迁移工具（可选执行）** | `dry-run` 先看计划再 `apply`；逐资产原子，可随时中断；不迁移也完全可用 |

## 10. 本地媒体根迁出 web 根（v1.5.0-beta.1 实施）

### 10.1 结论

| | 之前 | 之后 |
|---|---|---|
| 媒体根 | `public/uploads`（**web 根之内**） | `storage/uploads`（**web 根之外**） |
| 品牌 logo | `public/uploads/logo` | **不变**（站点静态资源，直读 + 长缓存） |
| 文件 URL | `/files?p=…&e=…&s=…`（短时签名） | 不变 |
| 静态直读 | **可以**（签名机制形同虚设） | 不可以（伪静态里 `storage/` 已 deny） |

关键事实：本地文件的 URL 从 v1.2.0 起就是短时签名端点（`LocalDriver::url()`），
所以**迁根完全不动读取端**——变的只是"文件放在哪"和"能否绕过签名直读"。

### 10.2 三类站点的行为

1. **新装** → 安装器默认写入 `storage/uploads`
2. **已装 + 本地有媒体** → 升级后**首个请求自动迁移**（10.3），无需人工操作
3. **已装 + 只用对象存储**（COS/OSS/S3）→ 无文件可迁；仅把本地实例记录的路径
   对齐到新默认值（若该实例路径原本就是旧默认值或空）

### 10.3 自动迁移的时序与失败语义

- **门禁**：`settings.local_root_layout = '2'`，**独立于 schema 版本** —— 文件迁移
  失败不会连累 DB 迁移每请求重跑。
- **阶段 1 搬迁**：只动**顶层条目**，逐个 `rename`（同盘为原子操作，瞬间完成）；
  跨盘退化为递归 copy + 删源；品牌 `logo/` **不参与**。
- **阶段 2 改配置**：本地实例 `config.path` 从 `public/uploads`（或空）→
  `storage/uploads`；**显式配了自定义路径的实例不动**（那是运维的选择）。
- **阶段 3 清旧根**：仅当旧根已空才 `rmdir`（logo 仍在时自然失败，无害）。
- **失败语义**：任一阶段失败 → 已搬条目**全部搬回** + 配置改动**回退**，最坏情况是
  "什么都没变"；失败**不写门禁** → 下个请求重试；原因写 `settings.local_root_error`，
  `doctor.php` 直接可见。
- **同名冲突**：目标已有同名文件时，尺寸一致视为已迁移（清掉旧副本）；尺寸不同 →
  报错并整体回滚 —— **绝不静默覆盖用户文件**。

### 10.4 读取端回退（迁移期间图片不会 404）

`/files` 按优先级依次尝试候选根，任一命中即返回：

1. 启用的**本地实例所配置目录**（URL 就是按各自实例生成的，多实例/自定义路径都覆盖）
2. `storage/uploads`（新默认根）
3. `public/uploads`（历史根 —— 迁根未执行或失败时的回退）

每个候选根都做 realpath 越权校验（**带分隔符**比较，`uploads-evil` 之类不会被误放行）。

### 10.5 部署侧同步清单

- **宝塔伪静态第 1 行必须含 `storage`**：旧版伪静态的 deny 段没有 `storage`，
  迁根后媒体会被 Web 服务器静态直读，签名机制失效 —— 这是本次升级**必须**同步的一项
- `BackupService`：备份收拢 `uploads/`（新根）+ `uploads-legacy/`（若仍有媒体）+
  `branding/`（logo），保证"能原样恢复"
- 安装器默认值、`doctor.php`（可写目录 + 两项新检查：媒体根是否在 web 根之内、
  历史根是否已清空）、`.gitignore`（`storage/uploads`、`storage/incoming`）

## 11. 读取路径性能与 URL 可缓存性（v1.5.0-beta.1）

### 11.1 修掉的三处读取路径开销

| 问题 | 原来 | 现在 |
|---|---|---|
| 每张图都查实例表 | `driverForImage()` 每张图 `find()` 一次 | 请求内行缓存（命中即返回，**save() 后清空**） |
| 每张图都新建驱动 | 云端要重建 SDK 客户端 + 逐条解密凭据；本地要做 realpath/目录探测 | 请求内驱动缓存（每实例一个） |
| 同一键重复签名 | `src` 取 sm、`srcset` 又含 sm → 同一键签两次 | `Image` 请求内 URL 记忆化（按 实例+键） |

量化（一页 60 张图 × 3 条 URL）：**查询 240 → 1；驱动/客户端构造 180 → 1；签名 180 → 60**。

`/files` 另外做成**快路径**：先试默认根与历史根（**零查询**），只有都没命中才去查实例表 ——
常规图片请求因此完全不碰数据库。

### 11.2 本地签名 URL 的稳定性（此前缓存完全失效）

`SignedUrl::url()` 原先 `expires = time() + ttl` —— 每秒都产生不同的 URL，浏览器把每次
渲染都当成新资源，`Cache-Control` 形同虚设，**每次浏览都把图片重新下一遍**。

现在把**签发时间对齐到 60s 窗口**：同一窗口内同一路径的 URL **完全一致**，过期时间 =
窗口起点 + ttl，因此剩余有效期恒在 `[ttl-60, ttl]`（默认 240~300s，不会刚生成就失效）。
窗口与响应头 `Cache-Control: private, max-age=60` 对齐。

### 11.3 对象存储：URL 每次都变，必须靠稳定域名

云端驱动走**预签名直链**，而签名里含签发时间（COS 的 `q-sign-time` 起点是 `time()-60`），
**无法在代码侧做成稳定 URL**。因此：

- **未绑 CDN/自定义域名** → 每次渲染 URL 都不同 → 浏览器缓存必然 miss → 每次浏览全量重下
- **绑了 CDN/自定义源站域名**（存储实例的 `cdn` 字段）→ `url()` 返回稳定地址 → 浏览器与边缘缓存生效

`doctor.php` 新增「云端图片 URL 稳定性」检查，直接告诉你当前处于哪种状态。

> 注：给对象写 `Cache-Control` 元数据也能提升缓存策略，但**只有绑定稳定域名后才有意义**；
> 本项目各云端驱动的 upload 未设置该元数据（改动需要逐家 SDK 验证，且只影响新上传对象），
> 因此优先级低于"绑域名"这一条。
