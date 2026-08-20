## v1.1.1

**版本概述**：管理后台与全局体验打磨版——排版/交互/无障碍统一、分类管理树形重构、Modal 与上传交互增强，并修复分类删除按钮失效，doctor 新增 config 目录防护自动探测。

### ⬆️ 功能增强（Enhancements）

- 全站排版层级统一（h1-h4 字号/行高/字距）、按钮按压态与焦点环、表格/表单/模态/滚动条打磨，prefers-reduced-motion 无障碍降级，响应式断点补全
- 前台导航小屏换行、Hero 统计卡微动效、在线测试面板容器化
- 后台侧边栏 active 菜单左侧指示条
- Modal 弹窗支持 ESC 关闭 + 打开时锁定背景滚动；图片上传进度条实时百分比；代码块限高滚动、复制按钮固定悬浮
- 分类管理改为「每个顶级分类一张卡片」的树形分组展示，子分类带连接线、层级缩进与虚线边框区分，操作按钮 hover 浮现
- doctor.php：新增自动探测 config 目录防护（HTTP 探测 /config/database.php 状态码，403/404=已防护）

### 🐛 Bug 修复（Bug Fixes）

- **分类管理**：修复删除按钮失效（分类树重设计时容器 id 变更导致事件委托未挂载）——改为 document 级委托

### 升级指南（Upgrade Guide）

1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。

完整变更日志请查看 [CHANGELOG.md](https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md)。
