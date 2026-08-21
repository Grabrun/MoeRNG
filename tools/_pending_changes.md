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
- **输入框宽度收敛**（2026-08-21）：短字段（input/select/toggle）max-width 420px，textarea 760px 上限，Logo 跨整行；移动端 ≤768px 恢复全宽
- **修复：数值字段 min/max 校验语义错误**（2026-08-21）：`audit_log_retention_days` 填 30 被误报"长度不能少于 7 字符"——根因是 min/max 被无条件按字符串长度校验；修复为数值字段只走数值范围校验，字符串字段才做长度校验
- **QA 审查修复**（2026-08-21）：
  - redirect 全部带 `?tab=` 参数（保存/校验失败/缓存清理/备份/测试邮件后正确停留在当前组，不再跳回基础设置）
  - 清除 4 处陈旧 anchor（`#performance`/`#backup`/`#mail` → `?tab=maintenance#maintenance`）
  - 补 `.settings-save-bar` sticky 底栏样式（此前遗漏，用户要求过"保存栏 sticky"）
- **Logo 上传合并为单按钮**（2026-08-21）：「选择图片」+「上传」合并为一个「上传」按钮——点击唤起文件选择，选中后预览并立即自动上传；移除 `data-logo-choose` 绑定与 disabled 状态
- **Logo 去掉 URL 粘贴框 + 默认预览**（2026-08-21）：删除「或粘贴外部 URL」输入框，改 hidden input 携带值（防保存清空）；未设置时预览默认 `/assets/logo.png` 并提示"当前使用默认 Logo"；移除 Logo 回退默认预览；**修复连带 bug**——logo_url 规则的 `url` 校验（FILTER_VALIDATE_URL）会把相对路径（`/assets/logo.png`、`/public/uploads/...`）判为非法导致保存必失败，已移除该校验
- **文案与实际行为对齐**（2026-08-21）：
  - `backup_period`：实现是间隔触发（24h/168h/720h），原文案误写「每周一/每月 1 号」→ 改为「每 24 小时/每 7 天/每 30 天」
  - `audit_log_retention_days`：规则 min 7 与文案「0 表示不清理」矛盾（填 0 会被校验拦截）→ min 改 0
  - `mail_encryption`：原文案「tls 按 ssl 处理」不实——实际 TLS/无 均为明文连接（未实现 STARTTLS）→ 文案如实说明，推荐 SSL
  - **安全修复**：备份目录 `backups/` 与 `.zip` 后缀未受 nginx/.htaccess 防护，备份 zip（含 DB+上传文件）可被公网下载 → 已加入 deny（nginx `location ~ ^/(...|backups|var)/` + `\.(sql|zip|...)`；apache 同步 `[F,L]` + FilesMatch zip）
- **移除死配置 `cdn_url`**（2026-08-21）：设置页「图片与存储」组的 CDN 域名字段实际只在旧库迁移时读取一次，真正生效的 CDN 在存储管理 profile 的 `cdn` 字段 → 从设置页 GROUPS 移除（media 组删除）、安装向导 step4 移除 local CDN 输入框、InstallController 不再写入；保留 Application.php 旧库迁移读取（老版本升级需把 cdn_url 迁入 profile）
- **恢复「图片与存储」组占位**（2026-08-21）：用户要求保留该组（后续开发图片处理/缩略图等设置）→ media 组恢复为空 fields 占位（desc 注明"后续版本提供；CDN 请到存储管理"），视图对空组显示占位文案、隐藏无意义的保存按钮
- **本地存储补 CDN 配置**（2026-08-21）：存储管理的 local profile 表单原来没有 CDN 输入框（LocalDriver 其实已支持 cdn 覆盖，只是 UI/后端未暴露）→ 表单加 CDN 字段（独立 id `cfg_cdn_local`，与 s3 的 `cfg_cdn` 区分）、JS 回填/提交按 driver 分支取值、后端 local 分支 config 保存 `cdn`
- **修复：CSP 拦截对象存储图片**（2026-08-21）：COS/OSS 等跨域图片在页面加载失败（浏览器直接访问 URL 正常）——根因是 v1.2.1 加的 CSP `img-src 'self' data: blob:` 只放行同源；修复为 DB 就绪后重新发送 CSP，img-src 动态加入所有 storage profile 的 CDN 域名与 bucket 默认 host（COS `{bucket}.cos.{region}.myqcloud.com`、OSS/AWS/endpoint/UPYUN/Qiniu）；DB 不可用时回退基础 CSP
  - **按 provider 精确推导**（用户指正）：AWS 是通用 S3（可接 MinIO/R2 等 S3 兼容网关）→ 有 endpoint 时只放行 endpoint host，不再无差别猜 `s3.{region}.amazonaws.com`；仅无 endpoint 时兜底 AWS 官方默认域名；COS/OSS/UPYUN/Qiniu/OBS 各按自身规则推导（SELECT 增加 provider 列）

_（继续迭代积累）_

---

_（本清单由本地迭代维护，发版时清空）_
