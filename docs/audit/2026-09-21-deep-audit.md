# 全库深度审计报告 · 2026-09-21

审计范围：`src/app`（模型 / 控制器 / 核心 / 中间件 / 存储）、`src/views`、`src/public/js`、
`src/*.php`（路由、doctor）、`schema.sql`。
第三方 `src/sdk/**` 只做完整性校验（`sdk_integrity_check.js`），不逐行审计。

方法：**先跑既有 22 项 harness 作基线**，再针对 harness 覆盖不到的面做结构化扫描
（模型↔DB 列一致性、死代码、静默 catch、XSS / 注入 / 凭据 / CSRF、文件规模），
并对"测试为什么没抓到"做归因。新发现的可长期守卫项已固化为门禁。

---

## 一、P0 —— 已修复（commit `68df344`）

### 1. `thumb_bytes` 漏写 `Image::$fillable` → 上一批功能**实际失效**

**根因链**

```
Model::hydrate($row)  →  new static($row)  →  fill($row)
fill(): if ($key === primaryKey || in_array($key, static::$fillable, true)) { ... }   // 不在名单 → 丢弃
```

`thumb_bytes` 未列入 `Image::$fillable` → 从 DB 取出的行里**被静默丢弃** →
`thumbBytesFor()` 永远返回 `null` → **API 的缩略图 `file_size` 永远为 `null`**。

值得注意的是：**写入路径完全正常、DB 里数据也正确**，唯一出问题的是读取 —— 所以
"数据在库里"这类人工验证也发现不了。

**为什么全部契约测试都绿（本轮最重要的方法论收获）**

当时的断言是 `payload.includes("'file_size' => …thumbBytesFor($actual),")`、schema 里有
`thumb_bytes` 列、SQL 里写了该列 —— 这些断言证明的是「**源码里有这段**」，
而本 bug 的特征正是「代码形状完全正确、行为失效」。**形状断言无法覆盖 hydrate 过滤。**

**修复**

| 项 | 内容 |
|---|---|
| 代码 | `Image::$fillable` 补上 `'thumb_bytes'`，并在原处写明原因（防日后被"整理"掉） |
| 通用门禁 | 新增 `.dsh/model_fillable_audit.js`（**error 级**）：交叉校验「DB 列」×「以 `$this->attributes['x']` 读取的列」 |
| 覆盖 | 全部 **7 个模型** + **运行时 `CREATE TABLE`**（`audit_logs` / `api_stats` / `visit_stats` / `rate_limits` 不在 `schema.sql` 里，由 `Application.php` / `RateLimitMiddleware` 建 —— 只读 schema.sql 会漏掉） |
| 回归断言 | `thumbs_contract` +2 条：`$fillable` 必须含 `thumb_bytes`；审计文件必须存在 |

> 修完复跑：`✓ 模型一致性审计通过（7 个模型，0 条提示）`。

---

## 二、P1 —— 审计基础设施缺陷（已改）

### 2. 「警告级检查 = 不存在」：这条 bug **早就被报过**

`audit_data_security.js` 一直在输出：

```
⚠ Image(images) fillable 未包含: thumb_bytes（若确实不可写请显式确认）
```

但它混在 `✓ 数据层/安全层审计通过（5 条提示需人工确认）` 里 —— 我把这句话当成噪音
读了**好几个版本**，从未点进去看。**无人阅读的警告级审计等于没有审计。**

已处理：

- 该检查移交给 `model_fillable_audit.js`（**error 级**，更完整），`audit_data_security.js`
  里删掉重复实现并留指向说明（**单一来源**，避免两处规则漂移）。
- 该审计的提示数从 5 → 4（剩下的 4 条是安装向导无 CSRF，见 §五）。

---

## 三、P2 —— 结构与整洁（**未改代码，待你决定**）

| # | 发现 | 证据 | 建议 |
|---|---|---|---|
| 3 | **死 API**：`StorageInterface::configFields()` + `name()` | 接口 + **8 个驱动实现**，全仓（含视图/JS）**零调用点** | 要么接上（后台"存储实例"表单本可用它渲染字段、避免各处硬编码），要么删掉 —— 我没擅自删，它可能是为外部扩展预留的 |
| 4 | 小死代码：`Controller::isPost` / `Request::isPost` | 零调用 | 删 |
| 5 | **单文件过大**：`ImageController.php` **2897 行**、`app.js` **2826 行** | — | 结构债（非 bug）。可按职责拆：上传 / 队列 / 迁移 / 转换 / 清理 5 个服务类 |
| 6 | `src/sdk/**` 全量入库 | 最大单文件 2.1 万行（COS `Descriptions.php`） | 部署包体积偏大；可考虑按厂商按需下载。`sdk_integrity_check` 已在守完整性 |
| 7 | `defaultDriver`（StorageProfile）零调用 | — | 同 #4 |

> 死代码扫描的**方法论说明**（避免误导）：判定为「零调用」的方式是
> 「`name(` 在 `src/app` + `src/views` + `src/public/js` 中不出现，且不在路由里以字符串派发」。
> 首轮我漏了两个搜索面、并把比较条件写反，误报了 102 条（例如把被两个视图使用的
> `Image::srcset` 报成死代码）。修正后才得到上表的可信清单。`name()` 因视图里存在同名的
> 无关 `name(` 出现而**不确定**，需人工确认。

---

## 四、遗留取舍 —— 需你拍板

### 8. 「读不到对象长度的行」会被标记完成，之后不再重试

`encodeThumbBytes()` 与 `encodeThumbs()` 同策略：**恒写 `{"ok":1}` 标记**，
否则前端进度循环会反复重选同一批行、永不收敛（这是当初加标记的原因）。

代价：若某次 `size()` 读不到内容长度（网络抖动；或 CDN 用分块传输、响应头没有
`Content-Length`），该行照样被标记完成 → `file_size` 永远 `null`，且**点第二次"补全"也不会重试**。

三个方案：

| 方案 | 行为 | 风险 |
|---|---|---|
| A（现状） | 一律写 `ok` | 一次失败即永久放弃 |
| B | **只在全部档位都测到**时才写标记，否则留空 | 永久不可读的对象会让循环空转 —— 但前端已有 `rounds > 500` 上限保护 |
| C | 加"尝试次数"列，超过 N 次才落标记 | 最正确，但多一列 + 多一处状态 |

我的倾向：**B**（改动 1 行，且空转有轮次上限兜底）。等你定。

---

## 五、已验证「无问题」的部分（附证据，不是印象）

| 面 | 结论 | 证据 |
|---|---|---|
| SQL 注入 | ✅ | `Model` 有三道白名单：`assertIdentifier`（列名）、`assertOrderBy`（仅 `col ASC/DESC`）、`assertWhereFragment`（拒绝 `;`、反引号、注释符）。全库 21 处插值**全为白名单变量**（表名 / 整数）。`Image::random()` 的 `LIMIT`/`OFFSET` 来自 typed `int` 与 `random_int()` |
| XSS | ✅ | 视图里 `<?= $… ?>` 全部经 `h()` / `(int)` / `icon()` / 常量；前端无「用户数据 → innerHTML」拼接（唯一命中是 CSRF token 的固定模板，值来自 `getCsrfToken()`） |
| CSRF | ✅ | 非安装类 POST 路由 **40/44 有校验**；4 处例外全是安装向导（设计如此，且 install 重放漏洞此前已修：`step2/3/4` 均有 `installed` 校验） |
| 凭据 | ✅ | 无硬编码（唯一命中是 `QiniuSdkDriver` 的**签名拼接**，误报）；云端凭据经 AES-256-GCM 透明加解密 |
| 静默 catch | ✅（抽查） | 79 处，抽查的每一处都带"为何可忽略"的注释（DB 不可用降级 / best-effort 日志 / 单档失败不影响其它档），未见吞掉关键错误 |
| 幂等与收敛 | ✅ | `ok:1` 标记防回填死循环（取舍见 §四）；`processQueue` 有 `processing` 行复位与致命错误兜底 |
| PHP 语法/引用 | ✅ | 25 个视图 + 全 PHP 通过；无「使用但未定义」变量；无悬空自研函数调用 |
| 设计契约 | ✅ | 63 项（CSP 无 inline style / 类名零豁免 / 表格密度 / 标题单一来源等） |

---

## 六、门禁现状

| 项 | 结果 |
|---|---|
| harness 总数 | **23 项**（新增 `model_fillable_audit.js`） |
| 全量结果 | **全绿** |
| 规模变化 | `thumbs_contract` 71 → **73 项**（+2 条 P0 回归断言） |
| 本次代码改动 | 仅 `src/app/Models/Image.php`（`$fillable` +1 行 + 注释） |
| 交付包 | `MoeRNG-v1.5.0-beta.1-20260921-231118.zip` |

---

## 七、三条可复用的结论（已写入项目记忆）

1. **新增一列必须过四关**：`schema.sql` → **自迁移清单** → **模型 `$fillable`** → hydrate 语义。
   第 3 关最易漏，且症状是"功能失效但代码全对"。
2. **警告级检查等于不存在**：凡"漏了就等于功能失效"的检查一律 **error 级**，且**单一来源**。
3. **「源码里有这段」≠「功能是对的」**：关键字段/分支要断言**行为**（填进去能读出来）、
   **守卫条件**、**相对顺序** —— 死代码也能让文本断言通过（本会话已踩过 3 次同类）。
