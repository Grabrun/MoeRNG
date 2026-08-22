- **全站审计 + 加载速度优化（2026-08-22 14:05）**：审计——77 PHP 语法全过 / 110 JS id 引用全存在 / CSS 变量无未定义 / data-* 委托完整覆盖 / 安全复查通过；性能——① CSS/JS 加 ?v=APP_VERSION 版本号（9 视图，部署自动刷缓存）② 字体清理 1.5MB→160KB（104→10 个被引用 woff2，49 删 + 45 git rm，_package.py 排除 _stale）③ nginx gzip 配置加入 nginx.conf.example（传输体积降 ~70%）；教训——批量字符串替换误伤视图（?>> 破坏 17 文件），已 git 恢复重做，18 视图语法全过
- **移除首页统计文件缓存（2026-08-22 16:05，宝塔 open_basedir 拦截修复）**：上一轮加的 var/cache/home_stats.php 文件缓存在宝塔防跨站（open_basedir 站点根限制）下触发「跨目录读取已被拦截」，首页整页报错；已移除文件缓存回归直接 COUNT（走索引很快），消除文件系统依赖
- **修复后台页面失效（2026-08-22 13:50，CSP V-02 回归）**：根因①移除 unsafe-inline 后 inline onclick/onchange/onsubmit 全部被浏览器拦截（modal 开关/编辑按钮/验证码刷新/每页切换/confirm 对话框全失效）；根因②helpers.js 全站共享但执行各页面专属脚本无元素守卫 → 非对应页面 null TypeError 中断整个脚本 → 主题切换/nav/后续初始化全挂。修复——helpers.js 加统一 data-* 事件委托层（open/close modal、edit-category/user JSON、toggle-class、refresh-captcha、auto-submit、confirm、storage-driver-toggle）+ 各迁移块加页面存在性守卫；17 处视图 inline handler 全部转 data-* 属性（含 PHP 动态按钮 → JSON 编码）；settings csrf 守卫。验证：0 inline handler 残留 / 69 PHP OK / JS OK
- **应用 UI 深度分析报告（2026-08-22 13:30，P0/P1 全部）**：前台——theme-toggle 44px / hero 统计来源说明 / 特性「核心」徽章 / API 文档 sticky 锚点侧边栏 / 在线测试响应时间+状态码+localStorage 历史（文本不依赖签名 URL）；后台——仪表盘快捷操作网格 / 设置未保存提示（save-hint+beforeunload）/ 用户最后登录时间（users.last_login 运行时自迁移）/ 分类树图片数徽章（单次 GROUP BY）；已存在跳过：汉堡菜单/Lightbox/日志分页+CSV导出/API Key安全提示/settings搜索/展开折叠/滚动动画
- **修复 CSP V-02: 移除 unsafe-inline**（2026-08-22 12:15）：创建 CspNonce 工具类生成每请求 nonce；CSP 头改为 script-src/style-src 使用 nonce；迁移 9 个内联 script 块到 helpers.js；迁移 10 个内联 style 块到 style.css；9 个视图添加 helpers.js 引用；CSP 完全移除 unsafe-inline
# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.1-beta.2（2026-08-21 21:03 发布）

## 累计变更（自 v1.2.1-beta.3 起）

- **应用 MoeRNG Design Tokens 视觉设计**（2026-08-21，设计源 D:/projects/2026-08-21-10-49-13）：软暖粉紫主题落地——主色 #C389E8 粉紫/辅色 #6FE3C2 薄荷/霓虹 #FF5FD2（仅抽卡高光）；深色底 #1E1630 / 亮色底 #FFF7FC；大圆角（--radius-sm 12/--radius 20/--radius-lg 28 + pill 999）；粉紫柔影；按钮 pill 化；badge/分页 pill；表单 48px 高 + 粉紫 focus 光环；CSS 变量名全部保留只换值（全局换肤零风险）；硬编码旧色全部替换为令牌 rgba；hero 统计渐变文字改纯色（遵守禁渐变文字铁律）
- **gacha 抽卡动效**（新设计核心交互）：抽图成功时霓虹边框闪动 550ms + 图片过冲弹入 420ms（cubic-bezier(.34,1.56,.64,1)）；prefers-reduced-motion 自动降级
- **圆体字族加载**：home/后台 layout/登录/install 5 页统一引入 Google Fonts（ZCOOL KuaiLe 标题 + Nunito 正文 + JetBrains Mono 代码），font-display=swap 不阻塞渲染；国内网络不可达自动回退系统字体栈
- **hero 双栏布局**（Design Tokens §5）：左栏 banner + 副标题 + CTA + 统计，右栏抽图舞台卡片化（hero-stage 大圆角 + 粉紫浮影）；≤900px 单列
- **代码审计修复**（2026-08-22 00:11 全量审计）：76 个 PHP 文件 php-parser 全过、app.js node --check、CSS 括号配平、CSS 变量定义完整、JS id 引用无缺失、StorageInterface 6 方法全实现、构造参数顺序一致；修复 2 处 bug——① metaEl「已签名」判断用 p= 参数只对本地 /files 有效（COS/OSS q-sign-* 不命中显示为空）改为 url.includes(?) 判断临时链接；② API Tester catch 分支未 resolve requestDone → Promise 悬挂 → 按钮卡 Loading
- **字体拉丁基本集显式声明**（用户截图反馈 fonts 仍未应用）：根因 Google Fonts css2 API **省略 U+0020-007E 块**（假设系统字体兜底），浏览器字体匹配扫描所有块都不命中 U+0020 范围 → fallback 系统字体；修复——fonts.css 顶部插入 6 个 @font-face 块（Nunito 4字重 + JetBrains Mono 2字重），显式声明 unicode-range U+0020-007E、U+00A0-00FF 覆盖拉丁基本集；浏览器第一时间匹配 → 拉丁字符用 Nunito/JetBrains Mono（variable font 同一 woff2 含 400-800 全部字重字形，无伪加粗）；同时清理精简 fonts.css（删除 844 行冗余的 cyrillic/vietnamese 子集）
- **字体路由修复**（用户截图反馈 fonts.css 加载后页面仍用系统字体）：根因 nginx.conf.example 只路由了 /public/，/assets/ 未路由（fall through 到 index.php 返回 HTML 而不是 CSS）；修复——字体文件全部移入 /public/css/fonts.css + /public/fonts/（已有 /public/ nginx 路由覆盖）；8 个页面 HTML 引用改为 /public/css/fonts.css；fonts.css url() 改为 ../fonts/；nginx.conf.example 加 /assets/ 静态目录路由（belt-and-suspenders）；git 105 个 rename 完整保留历史（用户反馈 Google Fonts 加载失败）：下载 ZCOOL KuaiLe/Nunito/JetBrains Mono 全部 104 个 woff2 分片（1.5MB）到 src/assets/fonts/，生成 fonts.css（@font-face + unicode-range，108KB）；8 个页面（home/admin layout/login/install 5页）的 Google Fonts 链接全部替换为本地 /assets/fonts/fonts.css；国内部署不再依赖外网字体
- **lightbox 全屏覆盖修复**（用户截图反馈顶部 nav/底部 feature 卡片仍可见，lightbox 没全屏覆盖）
- **修 lightbox 眼睛按钮无反应 + API Tester redirect 模式只能点一次**（用户反馈）：rd-zoom 眼睛按钮之前未绑事件（只有图片本体绑 click），现已补 openLightbox 函数双绑；API Tester redirect 模式 click handler 立即返回导致按钮瞬间重新启用 + 用户 Image 加载前再点第二次被丢失（dev tools 无第二次请求），改用 Promise 等 onload/onerror 完成才 await requestDone → 才重新启用按钮（用户反馈图片右下、左栏文字溢出）：.hero-grid align-items:start（两栏按内容自然高度）+ .hero-left/.hero-stage min-width:0（grid item 默认 min-width:auto 会撑爆布局）+ .hero-inner padding 0 24px + .hero-stage align-self:start（用户反馈图片比例不对不匹配）：.rd-preview 改 1:1 + max-width 480px + margin auto（容器稳定 480x480）；img 改 max-width/max-height 100% + object-fit contain（保留图片原比例不裁切，之前 4:5 + cover 把横版图裁成近正方形失真）（用户反馈图片比例不匹配、右侧留白）：.rd-preview 加 width:100% 撑满父容器；.hero-stage 去掉 max-width:520px + justify-self:center 改为 width:100% 撑满右栏（用户反馈横版图把 hero-stage 撑爆）：.rd-preview 固定 aspect-ratio 4/5 + max-height 360px；图片 width/height 100% + object-fit cover（容器比例固定 → 图片比例恒定）；.hero-stage 加 max-width 520px + justify-self center 防止右栏过宽
- **移除历史缩略图**（用户反馈：签名链接默认 5 分钟 TTL 过期后历史图全裂，功能无用）：删除 home.php rd-history HTML 区块、app.js saveHistory/renderHistory/HISTORY_KEY 整套逻辑
- **Hero CTA 居中**（用户反馈电脑端按钮没居中）：.hero .btn-group / .stats 加 justify-content: center、.hero .stat text-align center
- **导航微调**（用户反馈）：菜单文字居中（inline-flex + justify-content: center，横排/汉堡两态一致）；导航 logo 34px→40px（设计规范桌面 ≥32px）
- **logo 保持不变**（用户明确要求：只应用视觉设计，logo 不变——未引入设计源 logo-01~10，现有 /assets/logo.png、favicon、banner 全部不动）

---

_（本清单由本地迭代维护，发版时清空）_
