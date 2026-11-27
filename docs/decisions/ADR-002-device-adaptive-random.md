# ADR-002：随机图按设备取横/竖图（`device` 参数）

| 项 | 值 |
|---|---|
| 状态 | **Proposed**（2026-11-28 重写，替代同日初稿） |
| 日期 | 2026-11-28 |
| 影响范围 | `ApiController::random`、`Image::random`、`views/docs.php`、`README.md` |
| 版本建议 | 新功能 → 下一功能线 MINOR+1 |

## 1. 目标

桌面端别老给竖图，手机端别老给横图。允许调用方显式指定。

## 2. 全部规则

- **横图** = `width >= height`；**竖图** = `height > width`。不做 `square` 分类。
- 新参数 `device`：`pc` / `pe` / `auto` / `any`，默认 `auto`。
- `auto` 的判断：

  ```php
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  if ($ua === '') { $device = 'any'; }   // 无 UA 的老调用方，行为不变
  else { $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'pe' : 'pc'; }
  ```

- 该方向没有图：**忽略方向随机给一张**。不 404、不加 strict 模式。
- 响应**不加新字段**。`width/height` 本来就在响应里，方向由调用方自行判断。
- 尺寸为 0 的历史行只出现在 `any` 结果里。

## 3. 明确不做

| 不做 | 原因 |
|---|---|
| 生成列 + 索引 + 自迁移 | 几千张图直接 `WHERE width >= height` 就够；索引等真有规模问题再加 |
| `orientation` 参数 | 与 `device` 表达同一件事 |
| 互斥校验、参数冲突 400 | 只有一种参数，无从冲突 |
| `fallback=strict`、`fallback_applied`、`device_source` | 失败形态只有「给图」一种，不需要可观测字段 |
| `ClientDevice` 类、Client Hints | 一条 UA 正则够用 |
| 健康检查横/竖计数、后台设置项 | 不改变任何决策 |
| 服务端裁剪/letterbox | 不新增图片副本，方向只作为筛选条件 |

## 4. 唯一保留的兼容性处理

无 UA 请求归 `any`。没有这行，默认 `auto` 会把所有 curl 调用方切成只吃横图，
属于上线即破坏既有行为。这一行是必需的，不是防御性设计。

## 5. 实施

1. `Image::random()` 加可选方向条件，复用现有 COUNT + OFFSET 逻辑；
2. `ApiController::random()` 解析 `device`（§2 的代码）；
3. `views/docs.php` 参数表加一行，README 接口表加一行；
4. `.dsh/api_contract_test.js` 补：pc 只出横、pe 只出竖、无 UA 不过滤、无匹配时给任意图。

## 6. 修订记录

| 日期 | 变更 |
|---|---|
| 2026-11-27 | 初稿：生成列、索引、双参数、降级开关、检测来源回报等，过度设计 |
| 2026-11-28 | 按「不要过度设计」重写为单参数、单规则版本，砍掉索引与全部附加字段 |
