## v1.1.1-beta.5

**版本概述**：升级 doctor.php 的「Config dir not web-exposed」检查——从提示人工验证改为自动探测 `/config/database.php` 的 HTTP 状态码，直接给出 OK / FAIL 结论。

### ⬆️ 功能增强（Enhancements）

- doctor.php：自动回环探测 `/config/database.php` 访问状态（HTTP 403/404 = 已防护 → OK；HTTP 200 = 配置泄露风险 → FAIL 并提示补 nginx deny 规则；探测失败则降级为人工验证提示）

### 🐛 Bug 修复（Bug Fixes）

- （本版无修复）

### 升级指南（Upgrade Guide）

1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。

完整变更日志请查看 [CHANGELOG.md](https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md)。
