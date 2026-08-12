# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.0-beta.3（2026-08-12 11:52 发布）

## 累计变更（自 v1.2.0-beta.3 起）


- **默认 Logo + Favicon**：新增多尺寸像素艺术 logo（32/64/128/256 + 1024 中间档），1024 整数倍 NEAREST 缩放保留像素感；favicon.ico 多尺寸（16+32）；home.php <head> 接入 favicon；默认 logo_url 指向 /assets/logo.png（HomeController 兜底 + 安装/后台 default）


- **默认 Logo**：修复 doc-root=项目根 部署下 /assets/logo.png 404——logo/favicon 双位置放置（src/assets/ + src/public/assets/、src/favicon.ico + src/public/favicon.ico），兼容 public/ 与项目根两种 doc-root


- **默认 Logo**：去除外缘抗锯齿浅色像素（RGB≥80 → alpha=0），主体深紫黑像素艺术保留；2% 边缘透明化，dark/light 主题下都能干净融合


- **Favicon 修复**：PNG favicon 优先（/assets/logo-32.png）+ ICO 兜底 + ?v=20260812 破浏览器顽固缓存


### 🎨 品牌视觉（Brand）

- **Favicon**：换成 0197 猫耳头部特写（中心裁剪），16/32px 细节清晰；PNG+ICO 双格式
- **Hero banner**：首页 hero 新增 90474 横版品牌横幅（1400×466，圆角+阴影）
- **OG 分享图**：93348 竖版艺术图 + 深紫底 1200×630，加 og:image meta（社交分享卡片）
- **导航 logo**：保持 3712 不变


- **Hero banner**：裁剪 banner 去掉模糊的 "Random Image API" 英文小字（保 MoeRNG 大字 + 猫耳）；home.php 去掉下方重复的大标题/副标题/描述（banner 已表达品牌）


- **后台品牌**：管理面板应用品牌设计——侧边栏 logo（30px）+ 后台/登录页 favicon + 登录页 72px logo


- **全站品牌补齐**：install 5 页加 favicon + 64px logo；home hero banner 加 width/height 防 CLS


### 📊 后台仪表盘增强

- **仪表盘全面增强**：最近上传 6 图网格、分类分布渐变进度条（Top8）、系统概览（PHP/时区/存储驱动/未分类数）、帮助卡
- **全站改名**：Dashboard → 仪表盘（侧栏/标题/layout/controller，语言统一）


- **仪表盘再增强**：存储用量卡（总占用/平均/最大单图）、近 7 天上传趋势柱状图、最近操作日志（audit_logs 8 条）


- **仪表盘流量+系统状态**：API 调用量/网站访问量统计（新增 api_stats/visit_stats 按日计数表 + App\Core\Stats 打点，api.php 与首页埋点）、7 天双色趋势图、系统状态卡（CPU 1min/内存/磁盘进度条）

_（继续迭代积累）_

---

_（本清单由本地迭代维护，发版时清空）_
