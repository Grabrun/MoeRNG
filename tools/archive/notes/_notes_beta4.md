## v1.1.1-beta.4

**版本概述**：修复分类管理删除按钮失效的问题——分类树重设计时容器 id 变更导致删除事件委托未挂载，同时修复由此引入的 app.js 语法错误。

### 🚨 破坏性变更（Breaking Changes）

无

### 🚀 新功能（New Features）

- （本版无新增功能）

### ⬆️ 功能增强（Enhancements）

- 分类删除事件改为 document 级委托，未来容器结构调整不再导致按钮失效（commit 本版）

### 🐛 Bug 修复（Bug Fixes）

- **分类管理**：修复删除按钮点击无反应、无请求发出的问题——v1.1.1-beta.3 将容器 `#category-tree` 改名 `category-list` 后，原事件委托绑定在已不存在的 id 上静默失效（commit 本版）
- **全局 JS**：修复删除事件改造过程中残留的闭合括号导致的 app.js 语法错误，恢复 toast/modal 等全部前端交互（commit 本版）

### 📚 文档与依赖（Documentation & Dependencies）

- （本版无变更）

### 升级指南（Upgrade Guide）

1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。

### 贡献者致谢

- （本版无外部贡献者）

完整变更日志请查看 [CHANGELOG.md](https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md)。
