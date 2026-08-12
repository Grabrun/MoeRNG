## v1.2.0-beta.4

**版本概述**：视觉品牌全面升级 + 后台仪表盘重做 + 性能与可访问性大修。新增像素风格品牌体系（Logo/Favicon/横幅/分享图），仪表盘成为真正的运营中心（流量统计、系统状态、趋势图表），并修复多处稳定性问题。

### ✨ 新功能（Features）

- **品牌视觉体系**：像素猫耳 Logo 全站落地（导航/侧边栏/登录页/安装向导/Favicon/Hero 横幅/OG 分享图），透明背景、多尺寸整数倍缩放
- **后台仪表盘重做**：改名「仪表盘」——最近上传、分类分布、存储用量、7 天上传趋势、最近操作日志
- **流量统计**：新增 API 调用量 + 网站访问量统计（按日计数表，`/api` 与首页自动埋点），今日/近 7 天/累计 + 7 天双色趋势图
- **系统状态**：CPU 负载、PHP 进程内存、磁盘占用实时指标（纯 PHP 原生，无 shell 依赖）

### 🚀 性能优化（Performance）

- 品牌资源全部 WebP 化（banner 145KB → 24KB，LCP 显著提速），LCP 预加载 + `fetchpriority=high`
- 图片显式 `width/height`，消除 CLS 累积布局偏移
- 深色主题文字对比度提升（WCAG AA）

### 🐛 Bug 修复（Bug Fixes）

- 修复后台仪表盘 500：宝塔 `disable_functions` 禁用 `shell_exec` 导致 Fatal——改读 `/proc/cpuinfo`、`memory_get_usage`（纯 PHP 原生）
- 修复「全选」跨页接口 500（Model 对象数组访问）
- 修复上传进度条文字裁剪/遮挡，改为「文字行 + 胶囊渐变轨道」
- 修复批量上传超限误报 419（改为明确 413）
- 仪表盘视图防御性兜底（新旧文件混搭部署不再 500）

### 🎨 可访问性（Accessibility）

- 主页补 `<main>`、`meta description`、`sr-only` 标题
- 3 个 `select` 关联 `<label>`
- API 文档标题层级修正（h4 → h3）
- 后台「Dashboard」→「仪表盘」全站语言统一

### 升级指南（Upgrade Guide）

1. 下载 zip 覆盖部署（项目根 doc-root），重启 PHP-FPM 触发运行时迁移（自动创建 `api_stats`/`visit_stats` 表）。
2. 访问首页一次 + 调用任意 API 端点，触发统计埋点。
3. 运行 doctor.php 验证后删除。
4. 硬刷新（Ctrl+Shift+R）查看新品牌与仪表盘。
