# Changelog

本文件记录 MoeRNG 各版本的变更。格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

> **版本谱系说明**：`v1.5.0-beta.1` 是 2026-09-13 开启的开发线（本地媒体根移出 web 根、WebP 转换、
> 缩略图字节回填、API 尺寸选择等能力均在此线实装），开线提交 `b91204e` 明确「发布仍等待许可」；
> 随后该线被 v2 重构取代（`c888590` 取消全部 v1.5 兼容与迁移）——**v1.5 系列从未正式发版**，
> 无 tag / 无 GitHub Release，其能力已并入 v2.0.0-beta 系列。

## [2.0.0-beta.16] - 2026-10-03

### 前台各界面文案整体重写

- 首页：hero 副标题改为价值导向（调用一次/拿来即用/完全自控）；
  特性卡全部重写为顺畅叙述（无缓存无规律、子分类一起参与、低于 50ms 等）；
  抽图占位改为「点『试试手气』，随机取一张看看效果」
- 图库：引导语与空态重写（刷新页面换一批 / 去后台上传第一批）
- 关于页：简介改为定位式陈述（图片托管与随机接口一站搞定）；
  技术特性逐条打磨；开源寄语补充
- API 文档：统一全角标点与空格（分类标识（slug）、302 重定向、嵌套 JSON、
  sm（320）等），技术语义零改动
- 回归：PHP syntax 全绿

## [2.0.0-beta.15] - 2026-09-29

### 前台交互文案中文化（front.js 全面审计）

- **修复按钮文字不一致**：在线测试按钮 HTML 已为「发送请求」，但 JS 运行时
  点击后重置为英文「Send Request」——统一为「发送请求」
- 加载态「Loading...」→「加载中…」
- 状态徽标：OK→成功、Failed→失败、Network Error→网络错误、Error→错误
  （HTTP 标准 statusText 如 404 Not Found 保留不动）
- 测试历史记录文案全角化：「302 → 图片（200 成功，X）」；「错误：X」
- 随机图预览 alt「Random Image」→「随机图片」；meta 冒号全角化
  「分类：X」
- 回归：JS 语法（node --check）通过，英文残留复查为 0

## [2.0.0-beta.14] - 2026-09-29

### 前台文案优化（中文优先 + 一致性 + 渲染修复）

- 修复 docs 页渲染 bug：`**功能性要求**` markdown 星号残留 → `<strong>`
- 在线测试页中文化：按钮「Send Request」→「发送请求」；
  「Redirect (图片直出)」→「重定向（直接输出图片）」；结果提示同步
- 页脚中文化：「Open-source under MIT License」→「开源项目（MIT License）」
- 首页统计说明精简：「实时统计 · 图片与分类随后台更新」
- 关于页 GitHub 未配置提示改为面向访客的明确表述
- 回归：PHP syntax 全绿

## [2.0.0-beta.13] - 2026-09-29

### 图片处理页按钮合并：「重试失败项」

- 合并原「重试全部失败」+「重试元数据补全」两个按钮为单一「重试失败项」
- 一键依次完成三步：① 主图失败项（process_status='failed'）重新入队；
  ② 处理队列（含刚入队的失败项，进度条 + 统计联动）；
  ③ 缩略图元数据失败项（partial/failed）定向重试
- 快路径：两者皆空时提示「没有需要重试的失败项」，不再空跑请求
- 按钮禁用逻辑合并：主图失败与元数据失败皆无时才置灰
- 删除 queue-retry-meta 按钮（HTML + JS 事件），runThumbMetaRetry 函数保留复用；
  提示条措辞同步更新
- 回归：JS 语法（node --check）、PHP syntax 全绿

## [2.0.0-beta.12] - 2026-09-29

### 处理队列触发策略重构（按用户诉求）

- **移除**「页面加载 1.5s 后自动消费处理队列」—— 后台访问图片页不再随时触发处理
- **保留两个触发时机**（用户指定）：① 上传批次完成后自动跑一次（一次清空全部
  pending）；② 用户手动触发（图片处理页「处理」按钮 / 图片管理页「重试失败项」）
- **并发防护**：processQueue 标记 processing 改为条件更新（AND process_status='pending'），
  并发请求（上传完成 + 手动同点）不再重复拾取同一批；被抢先时整批放弃、
  下次轮询自然拾取剩余
- **batch 3 → 10**：前端 BATCH 与服务端默认值（processQueue / backfillThumbs）
  同步放大，积压处理轮数减少约 2/3
- 历史积压不依赖页面访问：`php src/cli/backfill-thumbs.php` 一次补齐
- 回归：PHP syntax 全绿、php_undef 全绿、JS 语法（node --check）通过

## [2.0.0-beta.11] - 2026-09-29

### 新增：前台图库缩略图一键补全（CLI 工具）

- **背景**：前台 /gallery 对「缩略图缺失」的图片回退加载原图 —— 275×206 的显示空间
  加载数 MB 原图，带宽浪费严重（用户反馈「使用了原图而不是合适的缩略图」）
- **根因**：缩略图为异步队列生成（processing_state.thumb_meta 停在 pending）；
  历史图（v2 迁移前）与从未触发队列的图没有缩略图 → displayUrl('sm') 回退链
  （sm → md → 原图）到底 → 前台 /gallery 加载原图。前台代码本身已正确使用缩略图
  （displayUrl('sm') + srcset sm,md + sizes 240px），无需改动
- **交付**：`src/cli/backfill-thumbs.php`（父循环脚本）+ `src/cli/backfill-thumbs-batch.php`
  （单批子进程）—— 构造带合法 CSRF 的 Request，走 ImageController::backfillThumbs()
  同一份生成/上传/落库逻辑（零分叉），部署后执行一次即补齐全部历史缩略图，
  前台 /gallery 自动改用 sm 320w 缩略图（1.16x 超采样，带宽 -90%+）
- **用法**：`php src/cli/backfill-thumbs.php [--batch 10] [--retry]`；
  亦可使用后台「图片管理 → 补全历史缩略图」按钮（同一逻辑）
- 回归：syntax 全绿、php_undef 全绿

## [2.0.0-beta.10] - 2026-09-29

### 修复：首页白屏（hotfix）

- **根因**：beta.9 在视图（front_header.php / admin/layout.php）中引入 `<script<?= CspNonce::attr() ?>>`，
  未限定名调用依赖「视图在 App\Core 命名空间上下文被 Controller::render() include」的隐式约定；
  若任一渲染路径不在该上下文（install 旁路等），解析为 \CspNonce 触发 PHP Fatal Error（Class not found）
  → 输出缓冲（ob_start）被丢弃 → 整页白屏，banner 未渲染（preload not used 伴随警告）
- **修复**：视图内改用全限定名 `\App\Core\CspNonce::attr()`（不依赖命名空间继承，任何上下文均正确解析）；
  字体切换脚本 try/catch 静默降级（异常不中断页面）
- **伴随**：CSP `connect-src` 放行字体两跳域（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com），
  消除 preconnect 被 `connect-src 'self'` 拦截的控制台报错
- 回归：全量 harness 全绿（syntax/design 63/api 67/hero 36/perf 74/layout 125/矩阵/scope/click/front 运行时）

## [2.0.0-beta.9] - 2026-09-29

### 前端性能优化（不影响功能）

- P1 零感知层：字体 preconnect ×2（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com）与 dns-prefetch；
  .htaccess 静态资源长缓存（public, max-age=31536000, immutable，ASSET_VER 指纹安全，Apache 对齐 nginx）
- P2 渲染层：字体 CSS 异步化（media=print + CSP nonce 合规脚本立即切回 all，display=swap 兜底无空白）
- P3 结构性：前台 JS 拆分 front.js（24.5KB 子集：toast/copyText/API 测试/随机图预览/reveal/统计滚动/动态样式），
  前台 JS 传输 157KB→56KB（gzip 后约省 30KB+）；后台 app.js 保持原样零回归
- 维护：layout_contract 版本断言语义化（v2 beta 线，不再写死 beta.2）；新增 docs/scripts/verify_front_runtime.js
  （front.js 前台 DOM 运行时等价验证）；新增 docs/scripts/patch_m6_perf.py（本次变更的可复现脚本）
- 回归：syntax 2912/2912、design_contract 63、api_contract 67、hero_stats 36、perf_contract 74、
  layout_contract 125、page/click/xref/ref 全绿、front.js 运行时链零异常

## [2.0.0-beta.8] - 2026-09-29

> 字体 CSP 修复：思源宋体/黑体恢复正常加载（此前被内容安全策略批量拦截，页面回退系统字体）。

### 🐛 修复

- **字体加载被 CSP 拦截**：M2 引入的字体镜像 `miaoda.feishu.cn` 的 `css2` 入口返回的 `@font-face` 实际指向 `sf3-scmcdn-cn.feishucdn.com` 的 woff2 分片（入口与字体文件两跳分离）；CSP `font-src` 此前仅放行入口域名，导致全部 505 个字体分片被浏览器阻止（控制台批量报错 `violates font-src 'self' https://miaoda.feishu.cn`，页面回退系统字体）
- **修复**：`font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com` —— 放行实测唯一的字体文件域名（精确最小化，未使用宽泛子域通配）

### ✅ 回归

- syntax 2912/2912；API 契约 67 项、design_contract 63 项、page_matrix / hero_stats 全绿

## [2.0.0-beta.7] - 2026-09-28

> 覆盖安装重置修复：发布包与仓库不再携带 `config/app.php`（`installed=false`），覆盖部署不再把已安装站点重置回安装向导。

### 🐛 修复

- **覆盖安装重置根因**：`src/config/app.php` 被 git 跟踪且被打进 release 归档——该文件由安装器写入（安装完成 `installed=true`），仓库/归档里的 `installed=false` 版本在覆盖部署时把安装状态覆盖重置，导致每次覆盖后重新显示安装界面
- **修复措施**：① `src/config/*.php` 整体移出版本控制（`git rm --cached` + `.gitignore`；database.php / signing_key.php 两条旧规则同步清理尾随注释——本项目 git 会把行中 `#` 及之后当作模式内容，带注释的规则静默失效）；② `build_release.py` 归档排除 `config/*.php`，仅保留 `config/.htaccess`（防目录列出的安全文件）
- 全新部署仍正常：归档无 config/ 时 `Config::load()` 自愈生成默认 `app.php`（`installed=false`）供安装向导引导，安装完成写盘 `installed=true`；此后任何覆盖（解压 release / git pull）都不会再触碰该文件

### ✅ 回归

- syntax 2912/2912；API 契约 67 项、design_contract 63 项、page_matrix 全绿；归档校验：`config/` 仅含 `.htaccess`，无 `*.php`

## [2.0.0-beta.6] - 2026-09-28

> 空库 404 优雅化：无图/分类无图时的 API 与测试页表现从「裸 404」改为可读引导。

### ✨ 改进

- **`/api/v1/random` 空库响应**：图库或所选分类为空时仍返回 HTTP 404，但 body 为结构化 JSON —— `error` 保持英文机器码 `No images found`（既有调用方零破坏），新增 `code: NO_IMAGES_AVAILABLE` 与中文引导 `message`（「图库暂无可用图片…请先在管理后台上传」）；分类为用户可控输入，插入前已 HTML 转义
- **在线测试页错误渲染**：JSON 分支对非 2xx 响应优雅化 —— 响应体是 JSON 时直接展示服务端 `message`/`error`；响应体非 JSON（nginx 默认错误页等）时提示「网关/伪静态（URL Rewrite）未配置，请求未到达应用层」并保留原始响应供诊断，不再裸显示 `404 Not Found / nginx`
- **API 文档**：补充空库行为说明（404 + JSON 错误体语义）

### ⚠️ 部署提示

- 若线上请求 `/api/v1/random` 仍见 nginx 默认 404 页，根因是 Web 服务器未启用伪静态（URL Rewrite）：`src/nginx.conf.example` 已注明，`location /api { try_files $uri /api.php$is_args$args; }` 未配置时请求不会到达应用层（与图库是否为空无关）

### ✅ 回归

- syntax 2912/2912；API 契约 67 项、design_contract 63 项、hero_stats 36 项、page_matrix / click_matrix / scope / click / xref 全绿

## [2.0.0-beta.5] - 2026-09-28

> M2「晴空画册」视觉重设计落地：全站换肤 + 前台/后台关键结构增强（按已评审设计提案 `design/ui-redesign.html` 执行）。

### 🎨 视觉重设计（全站）

- **双主题设计 token 换肤**：浅色（暖米白 `#FAF6F3` 底 + 珊瑚粉 `#E8597A` 主色 + 鼠尾草青 `#5F9E8C` 辅色 + 蜜橘 `#E8A24B` 点缀）/ 深色（炭紫黑 `#211E26` 底 + 提亮珊瑚粉 `#F2789A`）；圆角收敛（卡片 14 / 控件 10 / 大容器 18），阴影中性化，全面去霓虹与粉紫渐变
- **字体体系**：标题/数字走思源宋体（`--font-display: 'Noto Serif SC'`，含宋体/Georgia 回退栈），正文思源黑体 + 系统中文栈；`miaoda.feishu.cn` 自托管字体镜像（禁直连 Google Fonts），CSP 新增 `font-src` 并放行 `style-src` 字体域
- **前台 hero**：新增 kicker 眉标「自托管 · 真随机 · 治愈感」；banner 容器圆角收敛；stats 三数字加细分隔线（仅桌面横排态，不影响 hero_stats 对齐契约）；feature 卡改左对齐 + 图标前置
- **后台**：侧栏品牌走 display 字体；stat-card hover 上浮保留；设置页 tab 柔光/健康徽标残影随主题色（color-mix）

### 🔧 体积治理

- CSS 重复声明合并（btn/card/th/stat-card/toast/table-wrap/truncate 等 18 处）、reduced-motion 四块收敛为一、粉紫硬编码 rgba 换 color-mix 跟随主题
- 新增 `.gitattributes`（`style.css eol=lf`）：稳定字节口径，消除 CRLF 抖动；最终 `src/public/css/style.css` **90711B < 90KB 预算**
- 分页（transition/hover 上浮）、模态过渡、代码块高度限制等行为规则原样保留，无回归

### ✅ 回归

- design_contract 63 项全过（CSS 体积达标）；syntax 2912/2912；page_matrix / click_matrix / scope / click 全绿；hero_stats 36 项全过；xref_audit 367 个 class 全部可溯源；桌面+移动、浅色+深色截图自检正常

## [2.0.0-beta.4] - 2026-09-28

> 后台「系统设置」排版与 UI 优化（全 CSS 增强，结构/交互挂钩零变动）。

### 🎨 视觉增强

- **设置标签行 pill 化**：active 标签渐变底 + 柔光，非 active 悬停高亮
- **分组卡片标题左缘强调条**：渐变竖条区分分组层级，描述区留白对齐
- **设置项 hover 高亮**：浅紫底 + 圆角，label 加粗；输入控件聚焦环已有，整体层级更清晰
- **批处理面板标题状态点**：统一视觉锚点
- **健康检查结果状态徽标**：`[ OK ]` 绿 pill / `[待修复]` 红 pill（renderHealth 输出 health-ok/health-bad 类）
- **保存栏**：未保存提示改为警告色；工具栏窄屏自动换行

### ✅ 约束保持

- design_contract 63 项全过：分组头/工具栏/3×batch-panel/22 个 JS 挂钩 id/关键文案零变动；CSS 92141B < 90KB 预算；CSP 无 inline style
- scope/click/page_matrix/syntax 全绿；桌面+移动截图自检正常
## [2.0.0-beta.3] - 2026-09-28

> 全站代码检查 + 图片处理架构审计：真实性能修复 3 处 + 死代码清理；域服务抽取因 harness 契约锁定暂缓（路径已文档化）。

### 🔧 维护与性能

- **上传循环 finfo 提出循环**：批量上传时 fileinfo 库不再每张图重建（仅循环外打开一次）
- **大图流式下载**：`FileController::show()` 清 gzip 输出缓冲后 `readfile` —— 此前整文件进缓冲再压缩（图片已压缩，gzip 白费 CPU 且内存翻倍）
- **基线余量常量化**：两处裸 `16777216`（解码/转码 16 MiB 基线）收敛为 `MEMORY_BASELINE_HEADROOM`
- **死代码清理**：helpers.php 删除与 `h()` 完全重复且全站零调用的 `e()`

### 📄 文档

- docs/audit/2026-09-28-image-arch-audit.md：全站检查结论 + 图片处理架构审计 + 域服务抽取边界（harness 方法体级契约锁定；建议先解耦测试再迁移，顺序与落点已给出）

### ✅ 回归

- 全量 19 项 harness 全绿：layout 125 / thumbs 118 / convert 71 / memory_guard 46 / queue 143 / perf 74 / api 67 / design 63 / hero 36 / click_matrix / page_matrix / ref_check / xref / php_undef / data_security / scope / click / verify_autoload；语法 2912/2912

## [2.0.0-beta.2] - 2026-09-28

> 前台「服务可用性」实装：从静态字面量 99.9% 变为近 7 天 API 请求成功率（SLA 口径），随真实请求流量变化。

### ✨ 新功能

- **服务可用性实装**（首页 hero 第三个统计）：`api_stats` 新增 `fail` 列；`api.php` 未捕获异常（5xx）单独记失败（`Stats::bumpFail`），正常响应（含 4xx 业务校验失败——服务正常响应即「可用」）仍走成功计数；`Stats::availability()` 计算近 7 天成功率，首页动态渲染
- **无样本兜底**：新装 / 尚无流量时 `availability = null`，前台显示默认宣传值 99.9%；有样本后显示真实值（1 位小数，如 100.0% / 97.3%）
- 首页统计注释与 hero_stats 契约测试同步（第三个数字为动态值语义，`data-count` 仍仅前两项）

### 🔧 维护

- 深度审计（2026-09-28）：`Application.php` backfill 改为从默认 profile 推导（移除旧设置键 `storage_driver` / `storage_s3_provider` 读取）；`helpers.php` 删除死调试函数 `dd()`；全量 19 项 harness + 语法 2912/2912 全绿
- `SCHEMA_VERSION` 升至 `2026-09-28-2`（api_stats.fail 幂等迁移：SHOW COLUMNS probe → ALTER，权限被拒时降级不破坏站点）

### ✅ 回归

- hero_stats_contract（35）/ layout（125）/ thumbs（118）/ design（63）/ perf（74）/ queue（143）/ api（67）/ convert（71）/ memory_guard（46）/ click_matrix / page_matrix / scope / click / ref_check / xref_audit / php_undef / sdk_integrity / verify_autoload / model_fillable 全部通过

## [2.0.0-beta.1] - 2026-09-28

> **破坏性变更**（用户指令「取消对之前的所有兼容与迁移，使用新版本方案」）：强制统一资产中心方案（v2 布局 + 本地媒体根 storage/uploads），拆除旧布局 / 旧根 / 旧凭据的全部兼容层与迁移工具。升级前务必阅读 src/docs/STORAGE-LAYOUT.md 与 src/docs/BT-DEPLOY.md 的升级注意。

### 💥 破坏性变更

- **存储布局唯一化**：只认 v2 {yyyy}/{mm}/{uuid}/original.{ext} + thumb-{size}.webp；Image::assetParts() 不再识别 v1 形态（返回 null），thumbKey() 对非 v2 路径抛 RuntimeException
- **迁移工具整体移除**：POST /admin/images/migrate-layout 路由、「系统设置 → 存储结构与迁移」面板、app.js::runLayoutMigration/initLayoutMigration 全部删除
- **本地媒体根收口**：/files 读取不再回退 public/uploads 历史根（仅默认根 storage/uploads + 存储实例自定义根）；LocalDriver::legacyUploadDir() / LEGACY_REL_DIR 删除；升级前仍留在 public/uploads 的媒体需手工迁根
- **旧凭据迁移链删除**：migrateProviderCredentials() / migrateLegacyToProfiles() / insertProfile() / hasDefaultProfile() 移除，storage_profiles 是唯一配置来源；doctor 孤儿键检查清单仅保留真正无引用的键
- **清理工具收窄**：cleanupStorage 只保留 storage/incoming 暂存清理（删旧布局对象 / 历史根残留两类分支与统计）

### 🧹 死 API 收口

- StorageInterface::configFields()/name() 声明与 8 个驱动实现（LocalDriver/CosSdkDriver/ObsSdkDriver/AwsSdkDriver/OssSdkDriver/S3Driver/UpyunSdkDriver/QiniuSdkDriver）删除；providerFieldDefs() 保留
- Controller::isPost()、Request::isPost()、StorageProfile::defaultDriver() 删除（全库零调用，xref/ref_check 双验证）
- Application.php 删除整条本地根迁移链（runLocalRootMigration/migrateLocalMediaRoot/rollbackMedia/moveMediaEntry/rewriteLocalProfilePaths/revertLocalProfilePaths）与 LOCAL_ROOT_LAYOUT 门禁；DB 结构自迁移（runStorageMigration + ensure*）保留为部署机制，SCHEMA_VERSION 升至 2026-09-28

### 📄 文档

- src/docs/STORAGE-LAYOUT.md 重写为「仅 v2 布局」单一方案（删除兼容/迁移章节）
- docs/MAINTAINER-GUIDE.md 同步（存储抽象 / 读取快路径 / 已知缺口收口）
- src/docs/BT-DEPLOY.md 升级注意补 v2.0.0 迁根提示
- docs/PLAN-CANCEL-COMPAT-2026-09-28.md 拆除计划（范围表 + A-H 分层 + 验收标准）

### ✅ 回归

- 全量 harness 更新并全绿：layout_contract（125）/ thumbs_contract（118）/ click_matrix / design_contract（63）/ perf_contract（74）按 v2 语义同步；syntax 2912/2912；queue 143 / api 67 / convert 71 / memory_guard 46 / ref_check / scope_check / xref_audit / sdk_integrity / php_undef 全部通过
- 修复 Application.php 常量误删回归（SCHEMA_VERSION 使用未定义 → 补回声明）

## [1.5.0-beta.3] - 2026-09-22

> 深度审计（2026-09-21）驱动的收口迭代：API 缩略图选择、缩略图字节数落库、按处理项补全状态与定向重试。

### ✨ 新功能

- 随机图片 API 缩略图选择：`size=sm|md|lg|original` 四档位，在线测试器接线（ADR-001）
- API 响应只返回请求的图片：单 URL + 短 size 语义（不再混返回多档位）

### ✨ 增强

- 缩略图生成时记录实际字节数（`thumb_bytes`），存量库一键回填
- `processing_state` 列：逐图记录处理状态，支持定向重试失败项

### 🔧 修复

- `Image::$fillable` 补 `thumb_bytes` —— 此前该字段在 hydrate 时被静默丢弃
- 全站 18 项原生 harness 基线 + 4 项工具缺陷修复（syntax_check SKIP、verify_autoload 路径、audit_data_security 补列、p0_scan 范围收敛）
- 空 catch 补 best-effort 降级说明；doctor / 设置页输出符号改为纯文本（P0 纪律对齐）

### 📄 文档

- docs/audit/2026-09-23-deep-audit.md 深度审计报告（基线 18 绿 + 4 工具缺陷 + P1/P2/P3 发现清单）
- docs/MAINTAINER-GUIDE.md 接管基线、docs/DEV-PLAN.md 开发计划

## [1.5.0-beta.2] - 2026-09-14

> WebP 化 + 全站排版与页面节奏统一。

### ✨ 新功能

- 原图转 WebP：新上传默认转换 + 存量库批量转换工具

### 🎨 视觉

- 全站排版层级、页面节奏与表格密度统一；系统设置页 header 节奏 / 双栏网格 / 批量面板收拢

### 🐛 修复

- convert 干跑返回空 500：fatal 守卫 + 单图内存预算；imagewebp 需全画布缓冲，重校内存预算
- cleanup 干跑只扫一个批次（“0 items” 其实是没查全）
- 首页 hero 统计标签基线对齐，数字规则单一来源

## [1.5.0-beta.1] - 2026-09-13

> 统一资产中心布局 + 本地媒体根迁出 Web 根 —— 打开 1.5.0 测试线。

### ✨ 新功能

- 统一资产中心布局（asset-centric object keys）+ 原子迁移工具：老站点一键升级，迁移失败写入 `migration_last_error` 由 doctor 回显
- 本地媒体根迁出 Web 根（`storage/uploads`）；本地图片 URL 改为可缓存读路径
- cleanup 工具：清理升级残留 + 死代码 / 死 CSS / 多余产物

### 🚀 性能

- 读路径缓存存储 profile 与 driver（每请求一次解析）

## [1.4.0] - 2026-09-12

> 测试线 v1.4.0-beta.1 收口为正式版：处理队列重做 + 设置页补齐 + 性能热点清除。

### ✨ 新功能

- 处理队列重做：pending + failed 合并为可筛选列表，单行重试、失败详情面板、实时统计轮询
- 「清空队列」移到队列卡片右上角 popover；处理页去杂乱（按钮不换行、折叠文档与破坏性操作）
- 图片与存储设置 tab 补全（此前为空页）

### 🔧 修复

- 设置页卡死按钮（事件委托失效）；文件名 XSS 修复
- 全站三个每请求/每页热点清除（全站速度扫描）

### 📄 文档

- 统一存储布局提案（asset-centric 对象键）；部署文档同步

## [1.3.1-beta.1] - 2026-09-07

> v1.3.0 正式版之后的首个测试线：前台多页导航、公开图库页、资源缓存戳根治与性能优化。

### ✨ 新功能

- 前台多页导航：单页站点拆分为 `/`（首页）、`/docs`（API 文档 + 速率限制）、`/tester`（在线测试）、`/about`（关于）四个独立页面，导航当前页高亮
- 前台图库页 `/gallery`（公开访问）：按分类分区展示，每个分类随机 12 张（未分类单独区块），刷新换一批；点图新标签查看原图、一键复制链接
- 「管理面板」入口改为新标签打开（导航 + 首页按钮）

### ✨ 增强

- 资源缓存戳根治：`ASSET_VER = APP_VERSION + 静态资源 mtime`，全站 21 处 `?v=` 引用切换——发版或迭代改文件任一变化即刷新浏览器缓存，杜绝「代码已更新、样式不生效」
- 随机图片 API 性能优化：`ORDER BY RAND()` 全表 filesort 改为「COUNT + 随机 OFFSET」两步法，走索引直达目标行，图片量上万后延迟不再劣化（随机分布等价）
- nginx 静态资源长缓存：`/public/` 365d + immutable（配合 ASSET_VER 戳）；宝塔伪静态片段同步适配并补齐上传目录缓存头

### 🔧 修复

- 图库页排版：网格列数全断点固定（手机 2 / 平板 3 / 桌面 4），修复小屏碎片化；分页/空状态样式 CSP 合规

### 📄 文档

- BT-DEPLOY.md 伪静态配置更新（静态缓存块 + add_header 继承坑说明 + 缓存头校验命令）；前台介绍与 README 同步多页/图库形态

## [1.3.0] - 2026-09-05

> 测试线 v1.3.0-beta.1 / v1.3.0-beta.2 收口为正式版。1.3.0 聚合了 1.2.1 正式版以来的全部迭代：登录增强、三轮安全审计修复、凭据加密存储与密钥配置化、内网 SMTP 支持、以及多项 UI 修复。

### ✨ 新功能

- 登录增强：支持用户名或邮箱登录；「记住我」7 天自动登录（HttpOnly + Secure + SameSite Cookie，服务端只存 SHA-256 哈希，登出即注销）
- 存储凭据加密存储：`storage_profiles` 中的 AccessKey/SecretKey 以 AES-256-GCM 密封，密钥由统一配置体系管理（`config/credentials.php`，运行时生成）
- 自托管内网 SMTP 支持：`config/app.php` 显式设置 `allow_private_smtp => true` 后，`mail_host` 可指向局域网邮件服务器（云元数据地址始终拒绝）

### ✨ 增强

- API 文档侧边栏改为 tab 切换，支持四个 endpoint 间快速切换
- 系统设置分组 tab 分页展示；保存条改为贴底常驻工具栏
- 全站版本号单一来源化：`bootstrap.php` 的 `APP_VERSION` 是唯一定义处，页面展示 / 资源缓存戳 / 发布包文件名全部自动跟随
- 登录锁定升级为双维度：IP（原有阈值）+ 用户名（4 倍阈值），防换 IP 爆破单账号
- GitHub Token 改为项目级隔离（发布脚本自动读取，不落系统环境）

### 🔧 安全修复（三轮审计，共 15 项）

- Model 查询接口 SQL 注入加固（CWE-89）：标识符校验 / ORDER BY 白名单语法 / WHERE 片段危险字符拒绝 / LIMIT 强转
- 备份路径穿越（CWE-22）：绝对路径必须位于项目内，相对路径拒绝 `..` 段；备份目录加 Web 防护与随机文件名
- 审计日志敏感字段脱敏（CWE-532）：password / secret 类字段记录为 `***`
- API Key 不再在列表 / 编辑响应中返回完整明文（CWE-312），仅创建时一次性展示
- SMTP 主机防 SSRF（CWE-918）：解析后拒绝内网 / 保留 IP
- 全站 `hidden` 类与 `style.display` 配对冲突排查修复；三种形态重复 class 属性清理
- 管理后台时区显示规范化（PRC → Asia/Shanghai）；上传进度条 / 图片放大首击等多处 UI 修复

### 📄 文档

- 前台介绍与 GitHub README 全面更新（修正 API 参数示例 `format` → `type`，补充安全特性说明）；移除首页「跳到主要内容」skip link

## [1.3.0-beta.2] - 2026-09-05

beta.1 之后的增量：

### ✨ 增强

- 存储凭据加密密钥并入统一配置体系（`Config::get('credentials.key')`，持久化为 `config/credentials.php`，兼容迁移旧独立密钥文件）
- 自托管内网 SMTP 例外：`config/app.php` 的 `allow_private_smtp` 开关（默认 false，云元数据地址始终拒绝）

### 🔧 安全修复（审计第二批）

- 备份路径穿越加固 + 备份目录 Web 防护与随机文件名
- 审计日志 password / secret 字段脱敏
- CsrfMiddleware 显式短路返回；`Response::redirect` 拒绝 header 注入与危险 scheme
- API Key 列表 / 编辑响应移除完整明文

### 📄 文档

- 前台介绍更新（多存储实例卡片、技术特性列表）；README 修正 API 参数示例并补充安全特性；移除首页「跳到主要内容」skip link

## [1.2.1] - 2026-08-23

> 测试线 v1.2.1-beta.1 ~ v1.2.1-beta.3 收口为正式版。

### ✨ 新功能

- 随机图功能增强：Lightbox 大图预览 + 下载 + 元信息行
- 设置页按功能重排分组；Logo 上传合并单按钮
- 本地存储实例支持 CDN 配置；对象存储自定义源站域名（六家）
- SEO/a11y：JSON-LD / OG 补齐 / robots.txt

### 🔧 安全修复

- P0 级 install 重装漏洞：安装向导步骤全部加 installed 锁，杜绝已安装站点被重建管理员
- CSP nonce 加固；诊断端点保留管理员认证

## [1.2.1-beta.3] - 2026-08-23

### ✨ 增强

- API 文档侧边栏改为 tab 切换：点击左侧导航切换显示对应 endpoint（随机图片 / 图片列表 / 分类列表 / 服务统计），不再全部纵向堆叠
- 系统设置分组 tab 生效：设置页按分组分页展示，仅显示当前激活分组
- 设置保存条改贴底常驻工具栏：视觉上不再悬浮遮挡内容

### 🔧 修复

- 「存储用量」标题与数字遮挡：修复重复 class 属性导致 `.flow-summary-head` / `.text-small` 失效
- 系统设置 tab 首屏全板块同页显示：修复 PHP 条件输出与跨行两种重复 class 形态；tab 切换统一用 classList 并让高亮跟随点击
- 设置页滚到底保存条下方空档 + 孤悬「查看操作日志」链接：入口链接移至顶部工具条，页面撑满视口使保存条真正贴底
- API 文档首次进入空白：首个 pane 补初始 `active` 类，首屏即显示随机图片
- 在线测试重定向模式重复点击无请求：redirect 分支给 `<img>` src 加一次性缓存爆破参数；json 分支用 `fetch(cache: 'no-store')`，确保每次点击都发起新请求
- 取消自定义字体（Nunito / JetBrains Mono / ZCOOL KuaiLe），改用系统字体栈（system-ui / ui-monospace），移除 fonts.css 与 10 个 woff2 资源

## [1.2.1-beta.2] - 2026-08-21

### ✨ 新功能

- 随机图功能增强：Lightbox 大图预览 + 下载 + localStorage 历史记录（10 条）+ 元信息行
- 设置页按文档 §四 重排 5 组（基础/安全/图片与存储/系统维护/高级设置）
- Logo 上传合并单按钮（选择即自动上传）+ 默认 Logo 预览
- 本地存储实例支持 CDN 配置
- 对象存储自定义源站域名（COS/OSS/AWS/OBS/UPYUN/Qiniu 六家）
- SEO/a11y：Skip Link / JSON-LD / OG 补齐 / robots.txt

### 🐛 修复

- 数值字段 min/max 误按字符串长度校验（保留天数填 30 误报）
- CSP 拦截对象存储跨域图片（img-src 按真实 driver URL 动态白名单）
- 设置页操作后跳回错误分组（redirect 缺 ?tab=）
- logo_url 相对路径校验误判
- 备份目录 backups/ 与 .zip 后缀未受 nginx/.htaccess 防护（安全）

### 🧹 其他

- 设置项文案与实际行为对齐（备份周期/邮件加密/保留天数 0 语义）
- 移除死配置 cdn_url（CDN 统一走存储管理）
- 输入框宽度收敛 / 保存栏 sticky / 设置页排版优化

## [1.2.1-beta.1] - 2026-08-20

### 🔒 安全增强

- 会话加固（Cookie 属性/use_strict_mode/防会话固定）
- 限流键可信化（不信任 X-Forwarded-For）
- 签名密钥独立化（文件存储，与 DB 完全隔离）
- 限流 fail-closed / 全站安全响应头 / 信息泄露收敛 / security.txt

### ✨ 新功能

- 移动端汉堡导航（details 折叠）
- HEAD 路由支持 / doctor 签名密钥自检

### 🐛 Bug 修复

- 本地图片加载失败（signing_key 生成回归）
- 移动端 hero 布局 / banner 宽高比

### 🧹 工程整理

- tools/ 归档 / 根目录 assets/ 归位

## [1.2.0] - 2026-08-13

### 🔒 安全增强

- 图片链接全面改为短时临时签名（可配置有效期）：COS/OSS/AWS S3/OBS 云商原生预签名 + 本地 /files 签名端点
- 存储多实例配置（storage_profiles），运行时自动迁移

### ✨ 新功能

- 流量统计（API 调用量 + 网站访问量，按日自动埋点）
- 仪表盘重做：最近上传/分类分布/存储用量/7 天趋势/最近操作/系统状态
- 品牌视觉体系全站落地

### ⬆️ 功能增强

- 分页每页数量选择 / 跨页全选 / Dashboard → 仪表盘语言统一

### 🐛 Bug 修复

- 批量超限 419 → 413 / 全选接口 500 / 进度条文字 / 仪表盘 shell_exec 500

### 🚀 性能

- WebP + LCP 预加载 + CLS 消除 + 对比度 AA + 可访问性修复

## [1.2.0-beta.4] - 2026-08-13

### ✨ 新功能

- 品牌视觉体系：像素猫耳 Logo 全站落地（导航/侧边栏/登录页/安装向导/Favicon/Hero 横幅/OG 分享图），透明背景多尺寸整数倍缩放
- 后台仪表盘重做：改名「仪表盘」——最近上传、分类分布、存储用量、7 天上传趋势、最近操作日志
- 流量统计：API 调用量 + 网站访问量（api_stats/visit_stats 按日计数表，自动埋点），今日/近 7 天/累计 + 7 天双色趋势图
- 系统状态：CPU 负载、PHP 进程内存、磁盘占用实时指标（纯 PHP 原生，无 shell 依赖）

### 🚀 性能优化

- 品牌资源 WebP 化（banner 145KB → 24KB）+ LCP 预加载 + fetchpriority
- 图片显式 width/height 消除 CLS；深色主题对比度提升（WCAG AA）

### 🐛 Bug 修复

- 仪表盘 500：宝塔 disable_functions 禁用 shell_exec → 改 /proc/cpuinfo + memory_get_usage
- 「全选」接口 500（Model 数组访问）
- 上传进度条文字裁剪/遮挡（文字行 + 胶囊渐变轨道）
- 批量上传超限误报 419 → 413
- 仪表盘视图防御兜底（混搭部署不 500）

### 🎨 可访问性

- `<main>` / meta description / sr-only 标题 / select 关联 label / 标题层级修正
- 后台「Dashboard」→「仪表盘」语言统一

## [1.2.0-beta.3] - 2026-08-12

### 🔒 安全增强

- 图片链接改为短时临时签名链接（可配置有效期，默认 300 秒）：对象存储云商原生预签名（COS/OSS/AWS/OBS/又拍云/七牛，不经服务器代理）；本地存储新增签名下载端点 `/files`，永久静态 URL 改为短时签名链接

### ⬆️ 功能增强

- 图片管理分页：每页数量选择（10/20/50/100），保留筛选条件
- 图片管理跨页全选：「全选」按筛选选全部（跨分页），选中集合跨页保留
- 存储管理：本地存储可配置签名链接有效期

### 🐛 Bug 修复

- 图片上传：进度条重做（文字独立行 + 胶囊渐变轨道，不裁剪不遮挡）
- 图片上传：批量超限误报 419 → 413 + 前端预检
- 图片管理：全选接口 500（Model 数组访问）→ 轻量 SELECT id

## [1.1.1] - 2026-08-11

### ⬆️ 功能增强

- 全站排版层级统一、按钮按压态与焦点环、表格/表单/模态/滚动条打磨，prefers-reduced-motion 无障碍降级，响应式断点补全
- 前台导航小屏换行、Hero 统计卡微动效、在线测试面板容器化；后台侧边栏 active 指示条
- 分类管理「每个顶级分类一张卡片」树形分组，子分类连接线 + 层级缩进
- Modal ESC 关闭 + 背景滚动锁定；上传进度实时百分比；代码块限高滚动、复制按钮悬浮
- doctor.php 自动探测 config 目录防护

### 🐛 Bug 修复

- 分类管理：删除按钮失效（容器 id 变更致事件委托未挂载）→ document 级委托
- doctor.php 新增自动探测 config 目录防护（HTTP 状态码探测）

> 注：v1.1.1 正式版在 v1.2.0-beta.1 上线后补发收口（基于 v1.1.1-beta.5 代码状态）。

## [1.2.0-beta.2] - 2026-08-11

### ⬆️ 功能增强

- 存储管理表单改为服务商驱动：同一表单，选择服务商后字段/标签/占位/必填自动适配（又拍云：服务名/操作员名/操作员密码；OBS 显示 endpoint 等）

### 🐛 Bug 修复

- 存储管理：又拍云 USS 实例被统一校验（强制 Region）拦截 → 提交校验按服务商必填字段
- 图片管理：hover 时放大查看/复制链接按钮重叠 → `.copy-btn` 在图片卡内重置为静态 flex 项
- 图片上传：进度条百分比不显示（flex 布局修复）；上传完成后保存期显示「正在保存…」脉冲反馈

## [1.2.0-beta.1] - 2026-08-11

### 🚀 新功能

- 对象存储接入第 5、6 家：又拍云 USS + 七牛云 Kodo（全部官方 SDK）
  - sdk/upyun/：upyun/sdk 官方源码，psr7 v1→v2 适配后复用 COS vendor 的 Guzzle/PSR-7
  - sdk/qiniu/：qiniu/php-sdk 官方源码（自实现 curl 无 Guzzle），捆绑最小 MyCLabs Enum
  - 新增 UpyunSdkDriver（service + operator + password，无 region）与 QiniuSdkDriver（AK/SK + bucket + region z0-z3/as0/na0）
  - 存储管理页服务商下拉新增两家选项，doctor 新增对应 SDK 检查项

### 🐛 Bug 修复

- doctor.php：「Config dir not web-exposed」在宝塔/open_basedir 防护下的误报修复——HTTP 200 改为响应体关键词识别（宝塔拦截页 → OK；PHP 凭据特征 → FAIL；未知 → WARN 人工确认）

## [1.1.1-beta.3] - 2026-08-10

### ⬆️ 功能增强

- 后台分类管理改为「每个顶级分类一张卡片」的树形分组展示，子分类带连接线与层级缩进，二级以上子类用虚线边框区分
- 分类卡片头部显示顶级标识、子类数量与排序；子项操作按钮 hover 浮现，移动端始终可见

## [1.1.1-beta.2] - 2026-08-10

### ⬆️ 功能增强

- Modal 弹窗支持 ESC 关闭，打开时锁定背景滚动（body.modal-open）
- 图片上传进度条实时显示百分比
- 前台文档代码块限高滚动，复制按钮固定悬浮右上角

## [1.1.1-beta.1] - 2026-08-10

### ⬆️ 功能增强

- 全站排版层级统一（h1-h4 字号/行高/字距），按钮按压态与焦点环
- 前台导航小屏换行、Hero 统计卡微动效、在线测试面板容器化
- 后台侧边栏 active 菜单左侧指示条
- 表格/表单/模态/滚动条打磨，prefers-reduced-motion 无障碍降级，响应式断点补全

## [1.1.0] - 2026-08-10

### 🚀 新功能

- RESTful API：随机图片 / 图片列表 / 多级分类 / 服务统计
- 双模式返回：JSON 结构化数据 或 302 重定向直接输出图片
- API Key 鉴权 + 速率限制（DB token bucket）
- 对象存储：腾讯云 COS / 阿里云 OSS / AWS S3 / 华为云 OBS（官方 SDK）
- 管理后台：图片 / 分类 / 用户 / API Key / 存储实例（多实例配置）
- 系统设置 v2：站点信息 / 安全与访问 / 性能与缓存 / 邮件与通知 / 备份与恢复
- 登录验证码 + 失败锁定、SMTP 邮件通知、自动备份、操作审计日志
- 响应式前台：顶部导航、随机图展示、API 在线测试、关于页

### 📚 文档与依赖

- 新增 README（项目简介 / 安装部署 / API 用法）与 MIT LICENSE
- 对象存储 SDK 全部采用官方 SDK（qcloud/cos-sdk-v5、alibabacloud/oss-v2、aws/aws-sdk-php、esdk-obs-php）

---

完整变更日志请查看 [GitHub Releases](https://github.com/Grabrun/MoeRNG/releases)。
