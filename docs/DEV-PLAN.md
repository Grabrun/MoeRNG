# MoeRNG 开发计划 · 2026-09-23

> 基于接管基线（`docs/MAINTAINER-GUIDE.md`）与用户本轮需求（修复缺口 + 页面视觉重设计）。
> 里程碑按「稳定化 → 视觉重设计 → 结构债」排序；每个里程碑完成 = 全量 harness 全绿 + 迭代 zip 归档 + commit/push。

## M1 · 稳定化（建议立即执行）

| # | 事项 | 内容 | 验证 |
|---|---|---|---|
| 1 | **P1 自迁移缺口修复** | `Application::ensureImageColumns()` 补 `thumb_bytes` + `processing_state` 两列（含 AFTER 定位）；`SCHEMA_VERSION` 从 `2026-09-11` 递增（已打标站点才会重跑）；`audit_data_security.js` 识别列清单同步补两列 | `audit_data_security.js` / `model_fillable_audit.js` / `thumbs_contract_test.js` / 新增列四关自查 |
| 2 | **P2 CHANGELOG 补记** | 补 `1.4.0`（正式版收口）与 `1.5.0-beta.1~3` 迭代条目 | 逐项核对 tag/commit 记录 |
| 3 | 迭代归档 | 产出 `MoeRNG-v1.5.0-beta.1-{YYYYMMDD-HHMMSS}.zip` 入 `releases/`；commit + push main | `_package.py` 校验（sdk 条目数 / bootstrap 版本 / 关键文件） |

## M2 · 页面视觉重设计「晴空画册」（用户主项）

方向已出设计提案原型：`design/ui-redesign.html`（前台首页 + 后台仪表盘双视图，双主题）。

| 步骤 | 内容 |
|---|---|
| 1 设计 token 落地 | `style.css` `:root` 变量区重写：暖米白底 `#FAF6F3` / 炭紫黑深色 `#211E26`、珊瑚粉主色 `#E8597A`、鼠尾草青辅色 `#5F9E8C`、蜜橘点缀 `#E8A24B`；圆角收敛（卡片 14px、pill 仅按钮）；去霓虹与深紫渐变；标题尺度沿用现有唯一来源（h1 1.9 / h2 1.5 / h3 1.15，避免重开第二套） |
| 2 字体 | 标题引入思源宋体（Noto Serif SC 600/700）作 display 字体（衬线画册感），正文保留系统无衬线栈；字体加载失败有 fallback；CSP font-src 需同步放行自托管镜像域名 |
| 3 前台改版 | `partials/front_header.php`（topnav 粘性导航 + 主题切换）、`home.php`（hero 左文右图 + 随机图舞台 + 画册网格 + 两列特性清单）、`gallery/docs/tester/about` 套用新 token |
| 4 后台改版 | `views/admin/layout.php` 侧边栏（图标 + 分组）、dashboard 统计卡/最近上传/分类条/趋势条、表格与表单统一新样式 |
| 5 契约测试同步 | 先跑 `design_contract_test.js` / `xref_audit` / `click_matrix_test.js` 基线，逐项更新断言（标题尺度、设置页分组结构、表格密度、**元素 id 与 JS 接线不得丢失**）；UI 预览用 `gen_queue_preview.py` 交用户评审后再部署 |
| 6 交付部署 | 迭代 zip + 改动核心文件 + 部署纪律（重启 PHP-FPM 清 OPcache、清 cookie、跑 doctor、验证后删 doctor.php） |

**设计红线（沿用项目纪律）**：CSS 类名与 JS id 不随意删除（矩阵门禁）；`icon()` 仍是图标唯一入口；表格/设置页结构约定不变；双主题防闪烁 head 内联脚本保留。

## M3 · 结构债（按节奏，待拍板）

| # | 事项 | 备注 |
|---|---|---|
| 1 | 死 API：`StorageInterface::configFields/name`、`Controller::isPost`、`Request::isPost`、`StorageProfile::defaultDriver` | 深度审计遗留；接上用场或删除 |
| 2 | `ImageController.php` 2897 行拆分（上传/队列/迁移/转换/清理 5 服务类） | 结构债非 bug，拆分需跑全套契约回归 |
| 3 | 分类页死按钮（cat-collapse-all / cat-expand-all）收口 | OPEN-DECISIONS 待拍板 |

## 门禁与交付

- 每里程碑：23+ 项 harness 全绿（`xref_audit` / `audit_data_security` / `sdk_integrity_check` / `queue_contract` / `thumbs_contract` / `memory_guard` / `model_fillable_audit` / pre-commit 六件套）
- 发版：只有用户明确「发正式版」才发；迭代 zip 按版本线归档 `releases/`
- 每次迭代 commit 后立即 push main
