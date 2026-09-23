# 全库深度审计报告 · 2026-09-23

> 审计人：Doubao（接管维护后首轮深审）· 基线：`docs/MAINTAINER-GUIDE.md` / `.dsh/memory/MEMORY.md`
> 范围：`src/app`（52 文件 / 12841 行）、`src/views`（25 / 2848）、`src/public/js`（3533）、`src/public/css/style.css`（1793）、入口与路由、`schema.sql`、`.dsh` 工具链 22 项。
> 第三方 `src/sdk/**` 只做完整性校验（1276 文件基线全过）。
> 方法：**先跑全部 22 项 harness 作基线** → 工具失败项逐一定位归因（区分代码问题 / 工具缺陷 / 环境缺口）→ 对 harness 覆盖不到的面做结构化扫描（死代码、静默 catch、凭据、注入点、规模）→ 人工复核上轮审计遗留清单与安全面 → 归因"为什么此前没被发现"。

---

## 一、Harness 基线：18 项原生全绿，4 项工具链失败（已归因，非代码问题）

| 状态 | 工具 | 结果 | 失败归因 |
|---|---|---|---|
| ✅ 18 项 | api_contract(67) · convert_contract(71) · design_contract(63) · layout_contract(233) · memory_guard(46) · perf_contract(74) · queue_contract(143) · thumbs_contract(118) · model_fillable(0 提示) · audit_data_security · xref_audit(0 提示) · click_matrix · click_test · page_matrix · ref_check · scope_check · sdk_integrity · hero_stats(35) | 全绿 | — |
| ⚠ | `php_undef_check.js` | FAIL（MODULE_NOT_FOUND） | **环境缺口**：依赖 npm 包 `php-parser` 未安装。补装后实测：**57 文件 AST 级「使用但未定义变量」= 0** |
| ⚠ | `syntax_check.js` | FAIL（1/8585 语法错） | **工具缺陷**：SKIP 未含 `reference/`，唯一报错在第三方 upyun SDK vendor（不入库不部署）。排除 reference 后实测 **2912/2912 全过** |
| ⚠ | `p0_scan.py` | FAIL（263 违规） | **工具缺陷**：正则含 `2600-26FF`（✓✗★ 等文本符号）+ 未排除 `.dsh/`。按 MEMORY 规定范围 + 排除工具/第三方目录重扫：真实命中 **7 处，全部是注释/doctor 输出的文本符号，UI 代码零 emoji 功能图标违规** |
| ⚠ | `verify_autoload.py` | FAIL（6 MISSING） | **工具缺陷**：APP 路径写死 `ROOT/app`，实际在 `src/app`（过时）。修正后实测 **51/51 PSR-4 解析通过，46 个引用符号零缺失** |

> 结论：**当前工作树代码在修正工具后全量 harness 全绿**，4 个 FAIL 全部是工具链自身问题，无一是源码缺陷。但它们此前"跑不过也没人当回事"——这正是本轮最重要的方法论发现（见 §五）。

---

## 二、发现清单

### P1 · 已知缺口（上轮建立，本轮细化）

**`thumb_bytes` + `processing_state` 两列仍不在 Application 自动迁移列清单**（`ensureImageColumns`），`SCHEMA_VERSION` 仍 `2026-09-11`；schema.sql / 健康检查修复清单 / `$fillable` 均已含。

本轮补充的影响面证据：`processQueue` 的 COUNT / SELECT（ImageController:1035 / 1038 / 1049）**未包裹 try**，`$targetCond` 引用 `processing_state` 列 → 存量覆盖部署未补列时直接抛 `Unknown column` → 队列统计/补全 **500**（非优雅降级）；`doctor.php` 的缩略图检查 catch 后忽略（显示"统计失败"）。`backfillThumbs` / `requeueOne` 的 UPDATE 同样写这两列。

修复（并入 M1）：`ensureImageColumns` 补两列 + `SCHEMA_VERSION` 递增 + `audit_data_security.js` 识别列清单同步（它目前报绿但漏检，属"清单没跟上"型）。

### P2 · 新增（1 处）

**`DashboardController.php:32` `catch (\Throwable) {}` 空 catch 无注释**——违反项目纪律「静默 catch 必须带解释」。行为上安全（分类统计失败 → `$countsByCat` 空 → 分类分布全 0，`?? 0` 兜底），但属于纪律违反，建议补注释或显式降级。全库其余 69 处静默 catch 均带"为何可忽略"注释（best-effort / 表可能不存在 / 单档失败不影响其它档 / 迁移幂等），与上轮审计抽查结论一致。

### P3 · 结构债（上轮遗留，本轮重新确认保持未变）

| # | 事项 | 证据 |
|---|---|---|
| 1 | 死 API：`StorageInterface::configFields()` + 8 个驱动实现、`Controller::isPost`（Core/Controller.php:110）、`Request::isPost`（:74）、`StorageProfile::defaultDriver`（:246） | 本轮全库引用扫描：**均零调用**（`->x(` / `::x(` / callable 数组 / 路由字符串全搜） |
| 2 | `ImageController.php` **3017 行**（上轮 2897，2 天 +120 行，持续增长）、`app.js` 2906 行 | 单文件过大，结构债非 bug |
| 3 | `StorageProfile::defaultDriver` 注释「used by Image::getStorageDriver」过时（该方法已不存在） | grep 无 `getStorageDriver` |

---

## 三、已验证「无问题」的面（附证据）

| 面 | 结论 | 证据 |
|---|---|---|
| PHP 语法 | ✅ | 排除 `reference/` 后 **2912/2912 解析通过**（syntax_check） |
| 未定义变量 | ✅ | **57 文件 AST 级 0 命中**（php_undef_check，MEMORY 里两次生产 P0 的根因面） |
| PSR-4 装配 | ✅ | **51/51 解析通过、46 引用符号零缺失**（verify_autoload 修正版） |
| SQL 注入 | ✅ | `targetCond` 来自 `Image::thumbMeta*Sql()` 单一来源白名单 + `$fromSql` 强转 `(int)`（ImageController:1023-1038）；Model 三道白名单（assertIdentifier / assertOrderBy / assertWhereFragment）保持 |
| XSS | ✅ | 25 视图未见未转义用户可控字段（audit_data_security）；前端无「用户数据 → innerHTML」拼接 |
| CSRF | ✅ | 非安装 POST 40/44 有校验；4 处安装向导设计豁免，且 `InstallController` **5 个 step 方法均有 `installed` 锁**（防重装重建管理员） |
| 凭据 | ✅ | 1083 条候选**全部**为 SDK 路径 / 长字符串误报（src/sdk autoload.php 等）；`src/app` + 视图零硬编码 |
| 登录安全 | ✅ | 双维度锁定（IP 严格 + 用户名 4 倍宽松）、成功后重置计数、session 固定防护（登录旋转 ID）、remember-me 失败不阻塞登录（best-effort + 注释） |
| 静默 catch | ✅（1 处例外） | 70 处中 69 处带豁免注释；例外见 §二 P2 |
| SDK 完整性 | ✅ | 6 厂商 1276 文件基线、11 个入口、零字节文件检查全过 |
| 配置/敏感文件 | ✅ | `config/` 仅 app.php + .htaccess（database/signing_key/credentials 为部署时生成）；`debug_session.php` 不存在；`.dsh/`（含 moerng.token）在 .gitignore |

---

## 四、门禁现状与建议修复（并入开发计划 M1）

| 项 | 结果 |
|---|---|
| harness 总数 | 22 项（18 原生全绿 + 4 工具缺陷） |
| 建议新增/修复 | ① 补装 `php-parser`（环境依赖，建议全局安装或 `node_modules` 入 .gitignore）② `syntax_check.js` SKIP 加 `reference` ③ `p0_scan.py` 正则收敛至 MEMORY 范围 + SKIP 加 `.dsh`/`reference`/`tools`/`assets` ④ `verify_autoload.py` APP 指向 `src/app` |
| 契约规模 | 上轮 23 项 harness（含 model_fillable）→ 本轮 22 项全量基线，无一新增失败 |

---

## 五、方法论收获（"为什么 4 项 FAIL 此前没被发现"）

1. **没跑过的门禁 = 没有门禁**：22 项 harness 中 4 项长期 FAIL 而未进入「必须全绿」清单——基线必须包含"工具本身能跑"这一前置条件，工具跑挂了要立即修而不是跳过。
2. **记忆与工具两套规则会漂移**：`p0_scan` 的正则比 MEMORY 纪律宽（含 ZWJ / variation selector / 2600-26FF），"按记忆修工具"即治本；规则应单一来源（工具内注释引用 MEMORY 章节）。
3. **路径硬编码会随目录迁移过时**：`verify_autoload` 仍指向迁移前的 `ROOT/app`，迁移后无人更新——工具路径应通过探测（如 `glob(src/app)`）而非写死。

## 六、结论

当前工作树在修正工具链后**代码本体零新增 P0/P1 缺陷**；唯一功能性缺口仍是上轮 P1（自迁移缺列，待 M1 修复）。4 项工具 FAIL 为环境/工具问题，建议随 M1 一并修复，使 22 项 harness 成为真正可信的全绿基线。
