# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.1-beta.2（2026-08-21 21:03 发布）

## 累计变更（自 v1.2.1-beta.3 起）

- **应用 MoeRNG Design Tokens 视觉设计**（2026-08-21，设计源 D:/projects/2026-08-21-10-49-13）：软暖粉紫主题落地——主色 #C389E8 粉紫/辅色 #6FE3C2 薄荷/霓虹 #FF5FD2（仅抽卡高光）；深色底 #1E1630 / 亮色底 #FFF7FC；大圆角（--radius-sm 12/--radius 20/--radius-lg 28 + pill 999）；粉紫柔影；按钮 pill 化；badge/分页 pill；表单 48px 高 + 粉紫 focus 光环；CSS 变量名全部保留只换值（全局换肤零风险）；硬编码旧色全部替换为令牌 rgba；hero 统计渐变文字改纯色（遵守禁渐变文字铁律）
- **gacha 抽卡动效**（新设计核心交互）：抽图成功时霓虹边框闪动 550ms + 图片过冲弹入 420ms（cubic-bezier(.34,1.56,.64,1)）；prefers-reduced-motion 自动降级
- **圆体字族加载**：home/后台 layout/登录/install 5 页统一引入 Google Fonts（ZCOOL KuaiLe 标题 + Nunito 正文 + JetBrains Mono 代码），font-display=swap 不阻塞渲染；国内网络不可达自动回退系统字体栈
- **hero 双栏布局**（Design Tokens §5）：左栏 banner + 副标题 + CTA + 统计，右栏抽图舞台卡片化（hero-stage 大圆角 + 粉紫浮影）；≤900px 单列
- **字体路由修复**（用户截图反馈 fonts.css 加载后页面仍用系统字体）：根因 nginx.conf.example 只路由了 /public/，/assets/ 未路由（fall through 到 index.php 返回 HTML 而不是 CSS）；修复——字体文件全部移入 /public/css/fonts.css + /public/fonts/（已有 /public/ nginx 路由覆盖）；8 个页面 HTML 引用改为 /public/css/fonts.css；fonts.css url() 改为 ../fonts/；nginx.conf.example 加 /assets/ 静态目录路由（belt-and-suspenders）；git 105 个 rename 完整保留历史（用户反馈 Google Fonts 加载失败）：下载 ZCOOL KuaiLe/Nunito/JetBrains Mono 全部 104 个 woff2 分片（1.5MB）到 src/assets/fonts/，生成 fonts.css（@font-face + unicode-range，108KB）；8 个页面（home/admin layout/login/install 5页）的 Google Fonts 链接全部替换为本地 /assets/fonts/fonts.css；国内部署不再依赖外网字体
- **lightbox 全屏覆盖修复**（用户截图反馈顶部 nav/底部 feature 卡片仍可见，lightbox 没全屏覆盖）
- **修 lightbox 眼睛按钮无反应 + API Tester redirect 模式只能点一次**（用户反馈）：rd-zoom 眼睛按钮之前未绑事件（只有图片本体绑 click），现已补 openLightbox 函数双绑；API Tester redirect 模式 click handler 立即返回导致按钮瞬间重新启用 + 用户 Image 加载前再点第二次被丢失（dev tools 无第二次请求），改用 Promise 等 onload/onerror 完成才 await requestDone → 才重新启用按钮（用户反馈图片右下、左栏文字溢出）：.hero-grid align-items:start（两栏按内容自然高度）+ .hero-left/.hero-stage min-width:0（grid item 默认 min-width:auto 会撑爆布局）+ .hero-inner padding 0 24px + .hero-stage align-self:start（用户反馈图片比例不对不匹配）：.rd-preview 改 1:1 + max-width 480px + margin auto（容器稳定 480x480）；img 改 max-width/max-height 100% + object-fit contain（保留图片原比例不裁切，之前 4:5 + cover 把横版图裁成近正方形失真）（用户反馈图片比例不匹配、右侧留白）：.rd-preview 加 width:100% 撑满父容器；.hero-stage 去掉 max-width:520px + justify-self:center 改为 width:100% 撑满右栏（用户反馈横版图把 hero-stage 撑爆）：.rd-preview 固定 aspect-ratio 4/5 + max-height 360px；图片 width/height 100% + object-fit cover（容器比例固定 → 图片比例恒定）；.hero-stage 加 max-width 520px + justify-self center 防止右栏过宽
- **移除历史缩略图**（用户反馈：签名链接默认 5 分钟 TTL 过期后历史图全裂，功能无用）：删除 home.php rd-history HTML 区块、app.js saveHistory/renderHistory/HISTORY_KEY 整套逻辑
- **Hero CTA 居中**（用户反馈电脑端按钮没居中）：.hero .btn-group / .stats 加 justify-content: center、.hero .stat text-align center
- **导航微调**（用户反馈）：菜单文字居中（inline-flex + justify-content: center，横排/汉堡两态一致）；导航 logo 34px→40px（设计规范桌面 ≥32px）
- **logo 保持不变**（用户明确要求：只应用视觉设计，logo 不变——未引入设计源 logo-01~10，现有 /assets/logo.png、favicon、banner 全部不动）

---

_（本清单由本地迭代维护，发版时清空）_
