## v1.3.0-beta.2

**版本概述**：beta.1 之后的增量——存储凭据加密密钥并入统一配置体系、自托管内网 SMTP 例外开关、审计第二批安全修复与文档更新。

### ✨ 增强
- 存储凭据加密密钥并入统一配置体系（`Config::get('credentials.key')`，持久化为 `config/credentials.php`，兼容迁移旧独立密钥文件）
- 自托管内网 SMTP 例外：`config/app.php` 的 `allow_private_smtp` 开关（默认 false，云元数据地址始终拒绝）

### 🔧 安全修复
- 备份路径穿越加固（绝对路径须在项目内、相对路径拒绝 `..`）+ 备份目录 Web 防护与随机文件名
- 审计日志 password / secret 字段脱敏（`***`）
- CsrfMiddleware 显式短路返回；`Response::redirect` 拒绝 header 注入与危险 scheme
- API Key 列表 / 编辑响应移除完整明文（仅创建时一次性展示）

### 📄 文档
- 前台介绍更新（多存储实例卡片、技术特性列表）
- README 修正 API 参数示例（`format` → `type`）并补充安全特性
- 移除首页「跳到主要内容」skip link

### 升级指南
1. 下载下方 zip 资产覆盖部署。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。
