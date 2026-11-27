# ADR-002：随机图按设备返回横图/竖图

| 项 | 值 |
|---|---|
| 状态 | Accepted（2026-11-28 确认按「修数据源 + 查询层判定」实施） |
| 日期 | 2026-11-28 |
| 影响 | `Image::random()`、`ApiController::random`、`ImageController`（EXIF 纠正）、`docs.php`、`README.md` |
| 版本 | 2.2.0（新功能，2.1.1 已发布） |

## 规则

1. `?device=pc|pe|any` 显式指定：`pc` 取横图（`width >= height`，含方图），`pe` 取竖图（`height > width`），
   `any` 强制不过滤（浏览器 UA 也能测全量）。
2. 不传时按 UA 判断：含 `Mobile` / `iPhone` / `iPod` → `pe`，其余有 UA → `pc`，**无 UA（curl、服务端调用）→ 不过滤**。
3. 该方向没有图 → 去掉条件重取一次，不返回 404。
4. **判定依据必须可信**：`width/height` 存的是「实际显示尺寸」。竖拍 JPEG 的像素是横的、
   EXIF 标着要转 90°，上传时若原图未被转码就按 EXIF 交换宽高再入库（方向 5–8）；
   转码成功时图已被转正，`getimagesize()` 拿到的就是显示尺寸，不再交换。

## 不做

不加列、不加索引、不加迁移；不改响应结构（宽高本来就在）；不加 `fallback` / `orientation` / `device_source` 之类参数或字段；不动前台首页；不做方向占比统计。

## 改动

| 文件 | 改动 |
|---|---|
| `Image::random()` | 加可选 `$device`，SQL 多一个条件；空结果再跑一轮（约 +15 行） |
| `ApiController::random` | 解析参数 + UA 判断（约 +8 行） |
| `ImageController`（上传） | 未转码的 JPEG 按 EXIF 5–8 交换宽高（+6 行） |
| `ImageController`（批量转码） | 转码成功时回写真实宽高，历史竖拍照片顺带纠正（+8 行） |
| `docs.php` / `README.md` | 参数表各加一行 |
| `api_contract_test.js` | 6 条断言锁行为 |

## 已知代价

- 降级静默：`pe` 请求在全是横图的站会拿到横图，响应里看不出来。调用方该用 `object-fit: cover` 兜住构图。
- 宽度比较走不了索引；但 `random()` 本来就是全表 `COUNT(*)`，查询次数和扫描量级都不变。
- **历史竖拍照片仍可能记着横向宽高**（本次改动只覆盖新上传与今后转码的图）。要纠正存量：
  跑一次「设置 → 维护 · 媒体维护 → 转换历史原图为 WebP」，转码成功后宽高会按真实输出回写。
  不单独提供回填脚本 —— 转码功能本身就会产生正确尺寸，没必要为此再多一个 CLI 入口。
- EXIF 扩展不可用（`exif_read_data` 缺失）时无法判断方向，按原样入库，不猜。
