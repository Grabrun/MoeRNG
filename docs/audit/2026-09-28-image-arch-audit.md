# MoeRNG 全站代码检查与图片处理架构审计

- 日期：2026-09-28
- 范围：`src/`（PHP 应用层 + 前端 app.js + 存储驱动；排除 sdk/ 第三方依赖）
- 基线：v2.0.0-beta.2（HEAD `45182a3`）
- 方法：全量 harness 基线（19 项全绿）→ 图片处理链逐段精读（上传/转码/缩略图/内存守卫/队列/读取面）→ 定向扫描（缓冲/死代码/重复模式/未用符号）→ 架构优化实施 → 全量回归

## 一、全站代码检查结论

### 已达标面（审计确认，无需改动）

| 面 | 证据 |
|---|---|
| 语法 | php-parser 2912/2912 全过 |
| 引用完整性 | ref_check 72 调用均有定义；xref 0 条待确认；php_undef 57 文件零未定义变量；verify_autoload 46 符号全可解析 |
| 契约回归 | layout 125 / thumbs 118 / convert 71 / memory_guard 46 / queue 143 / perf 74 / api 67 / design 63 / hero 36 / click_matrix / page_matrix 全绿 |
| 安全面 | FileController `realpath` 越权校验（带分隔符比较）；上传 MIME+扩展名白名单、CSRF、单图大小上限、MD5+SHA-256 分层去重；helpers 转义规范 |
| 代码卫生 | 全站无 TODO/FIXME；注释口径完整（故障背景/教训/校准数据均留档） |

### 发现的问题与处置

#### 已修复（本迭代 v2.0.0-beta.3）

1. **上传循环内 `finfo_open/close` 每张图重建**（`ImageController::upload`）
   - 批量上传时每张图重复打开/关闭 fileinfo 库 → 提出循环，循环内仅 `finfo_file()`。
   - 影响：批量上传 CPU 与资源句柄开销下降；行为不变。
2. **`FileController::show()` 大图下载进 gzip 输出缓冲**
   - `Application::run()` 对启用 gzip 的站点挂 `ob_start('ob_gzhandler')`，`readfile()` 会把整个文件读进缓冲再压缩——图片本已压缩，gzip 纯浪费 CPU 且大图内存翻倍、流式失效。
   - 修复：`show()` 中循环 `ob_end_clean()` 清缓冲，恢复真流式输出（Content-Length 已知，走内核管道）。
3. **16 MiB 基线余量裸数字两处重复**（`originalConvertBudget` / `decodeWouldExceedMemory`）
   - 提取 `MEMORY_BASELINE_HEADROOM` 常量（与 `CONVERT_MEMORY_HEADROOM` 同值，语义独立）。
4. **helpers.php 死函数 `e()`**：与 `h()` 实现完全重复且全站零调用 → 删除（`h()` 保留）。

#### 判定为合法/有意设计（记录在案，不改）

- **两套内存预算并存**：`decodeWouldExceedMemory`（缩略图，w×h×4×1.25+32MiB）与 `originalConvertBudget`（原图转码，分阶段峰值取 max）——注释有完整校准史（73 MP 线上 OOM），口径不同是刻意为之。
- **`listFilesRecursive` / `pruneEmptyDirs` / `insertProfile` 等在 v2.0.0-beta.1 审计后**已逐一核实为当前功能复用（暂存清理/存储管理），非残留。
- **队列异步语义**（pending→processing→done + inflight 复位 + 致命错误标记 failed）为经过故障迭代的正确设计，不动。

## 二、图片处理架构审计

### 现状结构

图片处理管线全部内聚在 `Admin/ImageController`（2470 行上帝控制器）：

```
upload()  → 转码(convertOriginalToWebp) → 哈希去重 → 落 incoming → 记录 pending
processQueue() → 上传原图 → makeThumbnails(sm/md/lg) → uploadThumbs → 记录 done
convertOriginals() / backfillThumbs() / backfillHashes() / cleanupStorage()
```

处理单元：`decodeImage`（mime 优先 + 嗅探兜底）、`applyExifOrientation`（2~8）、`jpegOrientation`（0/1/2-8）、`originalConvertBudget`（分阶段峰值）、`decodeWouldExceedMemory`（缩略图预算）、`makeThumbnails`（三档一次解码）、`uploadThumbs`（逐档 finally 清理）。

### 架构问题

1. **上帝控制器**：CRUD + 图片处理 + 队列 + 后台工具混居一类的经典反模式——处理逻辑无独立命名空间、不可单独单测、后续新调用点只能继续往 Controller 塞。
2. **处理与 HTTP/DB 编排耦合**：解码/EXIF/预算/临时文件管理全部为 Controller 私有方法，`$this->` 依赖（profile 解析、设置读取、DB 写入）织入处理路径。

### 架构优化尝试与边界（重要结论）

**尝试**：抽取 `src/app/Services/ImageProcessingService`（解码/EXIF/临时文件/缩略图上传 6 方法），Controller 保留签名与方法调用形态、方法体委托。

**结果：回滚**。原因——现有 harness 对 ImageController 做**方法体级断言**：

- `thumbs_contract_test`：断言 `makeThumbnails` 方法体含 `@filesize($tmp)` 与 bytes 结构；断言 `uploadThumbs` 方法体内 `$keys[$size]` 在 `$bytes[$size]` 之前 + `return ['keys'=>…,'bytes'=>…]`
- `convert_contract_test`：断言 `originalConvertBudget` 方法体含 `self::jpegOrientation($file,$mime) >= 2`、`self::MEMORY_SAFETY_FACTOR`、`memory_get_usage(true)`、**不含** `thumbMaxPixels`；断言方法体注释保留线上 OOM 校准数据；断言 `jpegOrientation` 方向值域收敛
- `memory_guard_test`：断言 `makeThumbnails` 内 `decodeWouldExceedMemory($srcFile)` 在 `decodeImage($srcFile` 之前
- `queue_contract_test`：断言 `makeThumbnails` 开头总开关检查 + `thumbsEnabled/thumbQuality/thumbMaxPixels/uploadMaxBytes` 助手存在于 Controller

这些断言锁定了方法签名、方法体锚点行与调用顺序。**任何"方法体变薄委托"都会破坏契约**——委托实施中进一步确认：方法区间替换还会误删后续方法的注释块与属性声明（`$profileMemo`），风险远超收益。

**演进路径（建议，留待后续）**：
1. 若要解耦，先解耦 harness：把方法体级断言降级为"行为级"（例如改为对 `ImageProcessingService` 单测）——这是一次独立的、专门的测试架构迭代，不宜与功能改动同批；
2. 解耦后优先迁移顺序：`decodeImage` → `applyExifOrientation` → `jpegOrientation` → `tempConvertPath` → `discardThumbs` → `uploadThumbs`（已具备完整实现，落点 `App\Services\ImageProcessingService`）；
3. 内存预算（`originalConvertBudget`/`decodeWouldExceedMemory`）与队列（`processQueue` 等）牵涉最多契约断言，最后迁移；
4. 新功能一律落在 `App\Services\`，不再往 Controller 加处理逻辑。

## 三、验证

- 语法 2912/2912；全量 19 项 harness 全绿（含图片处理 6 项：layout/thumbs/convert/memory_guard/queue/perf）
- 改动文件：`ImageController.php`（finfo 循环外 + 常量）、`FileController.php`（流式下载）、`helpers.php`（删 e()）
- 未验证范围：真实 PHP 运行（无 PHP CLI，行为不变性由"零逻辑改动"原则保证——finfo 提循环为等价重构、流式下载为缓冲清理、常量替换为等价代换、e() 删除为无引用死代码）

## 四、结论

本轮为**维护 + 性能类迭代**（v2.0.0-beta.3）：真实修复 3 处（finfo 循环外、大图下载缓冲、魔数常量化）+ 死代码清理 1 处；图片处理架构的"域服务抽取"因 harness 方法体级契约约束而暂缓，路径与顺序已文档化。
