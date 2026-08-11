# Changelog

本文件记录 MoeRNG 各版本的变更。格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

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
