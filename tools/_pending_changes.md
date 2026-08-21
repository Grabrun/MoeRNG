# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.1-beta.2（2026-08-21 21:03 发布）

## 累计变更（自 v1.2.1-beta.3 起）

- **应用 MoeRNG Design Tokens 视觉设计**（2026-08-21，设计源 D:/projects/2026-08-21-10-49-13）：软暖粉紫主题落地——主色 #C389E8 粉紫/辅色 #6FE3C2 薄荷/霓虹 #FF5FD2（仅抽卡高光）；深色底 #1E1630 / 亮色底 #FFF7FC；大圆角（--radius-sm 12/--radius 20/--radius-lg 28 + pill 999）；粉紫柔影；按钮 pill 化；badge/分页 pill；表单 48px 高 + 粉紫 focus 光环；CSS 变量名全部保留只换值（全局换肤零风险）；硬编码旧色全部替换为令牌 rgba；hero 统计渐变文字改纯色（遵守禁渐变文字铁律）
- **gacha 抽卡动效**（新设计核心交互）：抽图成功时霓虹边框闪动 550ms + 图片过冲弹入 420ms（cubic-bezier(.34,1.56,.64,1)）；prefers-reduced-motion 自动降级
- **圆体字族加载**：home/后台 layout/登录/install 5 页统一引入 Google Fonts（ZCOOL KuaiLe 标题 + Nunito 正文 + JetBrains Mono 代码），font-display=swap 不阻塞渲染；国内网络不可达自动回退系统字体栈
- **hero 双栏布局**（Design Tokens §5）：左栏 banner + 副标题 + CTA + 统计，右栏抽图舞台卡片化（hero-stage 大圆角 + 粉紫浮影）；≤900px 单列
- **logo 保持不变**（用户明确要求：只应用视觉设计，logo 不变——未引入设计源 logo-01~10，现有 /assets/logo.png、favicon、banner 全部不动）

---

_（本清单由本地迭代维护，发版时清空）_
