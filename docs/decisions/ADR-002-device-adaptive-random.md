# ADR-002：随机图按设备形态自适应（PC / PE）与 `device` 参数

| 项 | 值 |
|---|---|
| 状态 | **Proposed**（待项目所有者确认，未实施） |
| 日期 | 2026-11-27 |
| 关联 | `ApiController::random`、`Image::random`、`src/schema.sql`、`views/docs.php`、`README.md` |
| 影响范围 | 随机图 API 取图集合、`/api/v1/images` 过滤、schema（新增生成列 + 索引）、文档页 |
| 版本建议 | 新功能 → **MINOR+1**，但须先把 `2.1.0-beta.4` 收口为 `2.1.0` 正式版，再开 `2.2.0-beta.1` |

---

## 1. 背景与目标

随机图 API 目前只按 `category` 与 `size` 取图，**与画面构图无关**。桌面端拿竖图会大面积留白，
手机端拿横图则主体过小。需求：同一接口按客户端形态给出合适构图的图。

三条硬要求：

1. 浏览器直接访问（`<img src=".../random?type=redirect">`）时**自动**给出匹配构图；
2. 调用方可显式指定，不被自动判断绑架；
3. 已有调用方（多为服务端 curl / 无 UA 客户端）行为不变。

**不新增任何图片副本**：方向由已有 `width`/`height` 派生，缩略图体系（sm/md/lg 按最大边缩放）原样复用。

## 2. 术语（先固定语义，避免后续歧义）

| 术语 | 定义 |
|---|---|
| **PC** | 桌面 / 横屏视口。要求**横构图**（`width >= height`，方图归此类） |
| **PE** | 移动 / 竖屏视口。要求**竖构图**（`height > width`） |
| **orientation** | 图片自身的构图属性，只有两值：`landscape`（w≥h）、`portrait`（h>w） |
| **unknown** | `width`/`height` 为 0 的历史行，方向未知，**不参与** pc/pe 命中，只出现在 `any` 结果里 |

刻意不做三分类（不加 `square`）：方图在横屏上比竖图更合适，`w>=h` 一条规则即可解释，
多一类只增加参数与文档成本。

## 3. 决策

### 3.1 参数

`GET /api/v1/random`

| 参数 | 取值 | 语义 |
|---|---|---|
| `device` | `auto`（默认）\| `pc` \| `pe` \| `any` | `auto` = 能判则判，判不出按 `any`；`any` = 完全随机（旧行为逃生口） |
| `orientation` | `landscape` \| `portrait` \| `any` | 更精确的等价写法；与 `device` **同时出现且语义冲突时 400**，不静默取其一 |
| `fallback` | `any`（默认）\| `strict` | 该方向无图时：`any` 忽略方向随机；`strict` 返回 404 + `NO_IMAGES_FOR_DEVICE` |

`/api/v1/images` 增加 `orientation` 过滤（批量场景无「设备」概念，不提供 `device`）。

### 3.2 自动检测优先级

```
device 参数  >  Sec-CH-UA-Mobile  >  UA 特征  >  any（判定不出，不是 pc）
```

- `Sec-CH-UA-Mobile: ?1` → `pe`；`?0` → `pc`（浏览器明确声明，最可信）
- UA 命中 `/Mobile|Android|iPhone|iPod|Windows Phone|HarmonyOS/` → `pe`；桌面 UA（Windows NT / Macintosh / X11）→ `pc`
- **无 UA、或 UA 是 `curl`/`python-requests`/`okhttp`/`Go-http-client` 之类** → `any`

最后一条是兼容性的关键：既有第三方多为服务端调用，不猜 `pc`，取图集合与今天完全一致。
代价是「桌面浏览器 UA 但识别不出」也归 `pc`，这是可接受的——它至少有真实 UA 可依据。

iPadOS 伪装 Macintosh 属已知盲区：靠 `Sec-CH-UA-Mobile` 或显式参数解决，不猜。

### 3.3 响应字段（只增不改）

```json
{
  "device": "pe",
  "orientation": "portrait",
  "device_source": "user-agent",
  "fallback_applied": false
}
```

- `device`：本次实际生效的形态（`pc|pe|any`）
- `orientation`：**返回的那张图**的构图，不是请求意图
- `device_source`：`param|ch|user-agent|none`，排障时一眼看出为什么拿到这个方向
- `fallback_applied`：为 `true` 表示走了降级（要的方向没图）。没有这个字段，降级就是静默的谎报

### 3.4 存储：VIRTUAL 生成列 + 复合索引

```sql
`orientation` TINYINT GENERATED ALWAYS AS (
    IF(`width` > 0 AND `height` > 0, IF(`width` >= `height`, 1, 2), 0)
) VIRTUAL,
INDEX `idx_feed` (`status`, `process_status`, `orientation`)
```

- `1=landscape`、`2=portrait`、`0=unknown`
- 选 VIRTUAL 生成列而非实体列：**零回填**、零写入路径改动，自迁移只做 `ADD COLUMN` + `ADD INDEX`；
  与既有 `thumb_meta` 同一范式（该列注释已说明「VIRTUAL 只为让判据走索引」）
- 表达式与 `Image::ORIENTATION_EXPR` 常量**逐字一致**，由契约测试锁死（同 `thumb_meta` 的做法）

### 3.5 取数路径单一化

新增私有 `Image::randomIn(string $where, array $params)`，现有 `random()` 与新增
`randomForOrientation()` 都走它 —— 两份 COUNT + OFFSET 逻辑漂移过一次就够受的。

方向有结果时不产生额外查询；结果为 0 且 `fallback=any` 时才补一次「忽略方向」的查询
（仅降级路径付一次成本）。

## 4. 红线

1. **不传任何参数时，服务端调用（无 UA）必须仍等价于 `device=any`** —— 不允许静默收窄第三方取图集合。
2. **`/random` 的 `Cache-Control: no-store` 必须继续覆盖所有分支**。同一 URL 因 UA 返回不同图，
   任何缓存都会把随机性打死；若将来引入缓存，必须同时上 `Vary: Sec-CH-UA-Mobile, User-Agent`，
   且这条改动要重开 ADR。
3. **不替调用方改 `size`**。`size` 与 `device` 正交；隐式降尺寸会让调用方流量/画质突变。
4. **`fallback_applied` 必须如实回报**，降级不允许静默。

## 5. 不变量

- `device=pc` 永不返回 `portrait`；`device=pe` 永不返回 `landscape`（`fallback_applied=true` 的降级除外，此时 `device` 仍回报请求值、`orientation` 回报真实值）。
- `width/height` 为 0 的行只能出现在 `any` 结果中。
- 缩略图仍按最大边缩放，方向与 `size` 互不影响。
- API Key / 限流 / 签名 TTL 行为不变。

## 6. 被拒方案

| 方案 | 拒绝理由 |
|---|---|
| 服务端实时裁剪 / letterbox 成目标比例 | 需要新的中间产物与存储，且破坏原构图；与「不新增副本」冲突 |
| 按 UA 把 URL 改写成 `?d=pc/pe` 再 302 | URL 不透明、破坏调用方缓存与签名，且 `<img>` 场景无法回写 |
| 用 `Vary: User-Agent` 让 CDN 缓存两份 | `/random` 本就 `no-store`，无收益；真要缓存则碎片化严重 |
| 自动替调用方选 `size`（PE→md） | 隐式改变流量与画质，属越权 |
| `ORDER BY RAND()` 加方向过滤 | filesort，现有 COUNT+OFFSET 已刻意避开 |
| 三分类 `square` | 参数与解释成本上升，方图归横屏已够用 |
| 加设置项切换默认值 | 每请求一个 `device=any` 就能表达，不必再进后台 UI |

## 7. 后果与风险

- **索引选择**：`orientation` 只有 3 值，选择性低；靠 `(status, process_status, orientation)` 前缀过滤，
  实际行数已很小。COUNT 仍是索引扫描，规模与今天的全表 COUNT 同量级，不新增数量级风险。
- **大表加索引**：MySQL 8 ONLINE DDL，VIRTUAL 列索引构建较快；文档需提示低峰执行。
- **竖图稀少**：图库若以横图为主，PE 请求会大量 `fallback_applied=true`。健康检查应显示
  「横图 X / 竖图 Y / 未知 Z」，让站长看见结构性缺口，而不是让调用方默默吃降级。
- **未知方向行**：老图若 `width/height` 为 0，永远不进 pc/pe。需要一条回填路径（复用现有元数据探测能力），
  或在健康检查里明示数量。

## 8. 执行拆解

1. `src/schema.sql`：加 `orientation` 生成列 + `idx_feed`；`Image::ORIENTATION_EXPR` 常量同步
2. `Application::schemaExpectations()` / `migrateSchema()`：检测列与索引，缺失则 `ALTER`
3. `Image`：抽 `randomIn()`；新增 `randomForOrientation()`；`list()`/分页查询支持 `orientation` 过滤
4. 新增 `App\Core\ClientDevice::detect(Request): array{device, source}`（CH → UA → any）
5. `ApiController::random`：参数解析、互斥 400、降级、响应四字段；`list` 支持 `orientation`
6. 文档：`views/docs.php` 参数表与示例、`README.md` 接口清单
7. 健康检查：横/竖/未知计数
8. `.dsh/device_contract_test.js`：检测优先级、参数互斥、降级语义、响应字段、`no-store` 位置、
   生成列表达式与常量逐字一致
9. 版本：`2.1.0` 正式版收口 → `2.2.0-beta.1`

## 9. 待确认

1. **PC / PE 的语义**：本方案按「桌面横构图 / 移动竖构图」实现。若 PE 实指别的（如某客户端形态、
   特定分辨率档），方向模型需替换。
2. **默认是否 `auto`**：本方案默认 `auto`，但无 UA 时归 `any` 保证兼容。若要求「所有请求一律自适应」，
   则第三级默认改为 `pc`，兼容性红线 1 需要撤销。
3. **前台首页是否一起自适应**：首页 `randomBatch` 一次取 12 张，若要按视口选图需另一套逻辑；
   本 ADR 只覆盖 API。

## 10. 修订记录

| 日期 | 变更 |
|---|---|
| 2026-11-27 | 初稿（Proposed） |
