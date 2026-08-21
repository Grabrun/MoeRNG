# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.0-beta.4（2026-08-13 03:34 发布）

## 累计变更（自 v1.2.1-beta.2 起）


### 🎨 前端 UI 深度分析报告落地（2026-08-20）

- **随机图功能增强**（I2）：大图预览 Lightbox（Esc/遮罩关闭）+ 下载按钮 + localStorage 历史记录（最近 10 条缩略图点击回看）+ 元信息行
- **Skip Link**（A1）：跳到主要内容（键盘/读屏可达）
- **SEO**（SEO1/2）：JSON-LD 结构化数据（WebApplication）+ OG 补齐（description/url/site_name/locale + twitter card）
- **a11y**（A2/A4）：banner alt 描述化 + 随机图区 role=region/aria-label
- **统计数字强化**（V2）：2.4rem 粉紫渐变文字
- **robots.txt**：Disallow /api/ /admin/ /files/ + Sitemap 引用

### ⚙️ 后台设置页优化（2026-08-21）

- **设置分组按文档 §四 重排为 5 组**：基础设置 / 安全设置 / 图片与存储 / 系统维护 / 高级设置（gzip+cors 性能项归入）
- **取消高级设置默认折叠**：5 组平铺展示，移除折叠按钮与相关 JS（用户明确要求）
- **排版优化**：`.settings-grid` 自适应列宽（不再固定 2 列）；logo/textarea 等长字段跨整行（`setting-item-wide`）；help 文案统一 `.setting-help` 样式；保存按钮独立 `.settings-save-bar` 底栏

_（继续迭代积累）_

---

_（本清单由本地迭代维护，发版时清空）_
