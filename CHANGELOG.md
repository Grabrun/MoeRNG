# Changelog

本文件记录 MoeRNG 各版本的变更。格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

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
