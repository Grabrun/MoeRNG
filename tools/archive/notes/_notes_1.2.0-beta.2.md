## v1.2.0-beta.2

**版本概述**：管理后台体验打磨——存储表单按服务商驱动，并修复存储校验、图片操作按钮与上传进度条四项问题。

### ⬆️ 功能增强（Enhancements）

- **存储管理表单改为服务商驱动**：新增/编辑存储实例共用同一表单，选择服务商后字段自动适配——字段显隐（又拍云/OBS 隐藏 region；endpoint 仅 OBS 显示）、标签换语义（又拍云：服务名/操作员名/操作员密码）、占位符给真实示例、必填红色星号跟随

### 🐛 Bug 修复（Bug Fixes）

- **存储管理**：修复添加又拍云 USS 实例被拒的问题——提交校验（前端 + 后端）改为按服务商必填字段：又拍云（AccessKey/SecretKey/Bucket）、OBS（AccessKey/SecretKey/Bucket/Endpoint）、其余（AccessKey/SecretKey/Region/Bucket）
- **图片管理**：修复 hover 时「放大查看」与「复制链接」按钮重叠——`.copy-btn` 全局绝对定位在图片卡内重置为静态 flex 项，按钮 30×30 + 间距 8px
- **图片上传**：修复进度条百分比不显示（display:block 覆盖 flex 布局）；上传 100% 后后端保存期显示「正在保存…」+ 脉冲动画，不再干等

### 升级指南（Upgrade Guide）

1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。

完整变更日志请查看 [CHANGELOG.md](https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md)。
