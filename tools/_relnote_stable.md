## v1.3.0（正式版）

**版本概述**：测试线 v1.3.0-beta.1 / v1.3.0-beta.2 收口为正式版。聚合 1.2.1 正式版以来的全部迭代——登录增强、三轮安全审计修复（15 项）、存储凭据加密存储、自托管内网 SMTP 支持，以及多项 UI 修复与文档更新。

### ✨ 新功能
- 登录增强：用户名或邮箱登录；「记住我」7 天自动登录（HttpOnly + Secure + SameSite Cookie，服务端只存 SHA-256 哈希，登出即注销）
- 存储凭据加密存储：AccessKey/SecretKey 以 AES-256-GCM 密封，密钥由统一配置体系管理（config/credentials.php，运行时生成，兼容迁移旧文件）
- 自托管内网 SMTP：config/app.php 设置 allow_private_smtp => true 后，mail_host 可指向局域网邮件服务器（云元数据地址始终拒绝）

### ✨ 增强
- API 文档侧边栏 tab 切换；系统设置分组 tab 分页；保存条贴底常驻
- 全站版本号单一来源化（bootstrap.php 的 APP_VERSION 唯一定义，展示/缓存戳/包名自动跟随）
- 登录锁定双维度：IP + 用户名（4 倍阈值），防换 IP 爆破单账号
- 发布工具链：GitHub Token 项目级隔离、打包版本号自动读取

### 🔧 安全修复（三轮审计，共 15 项）
- Model 查询接口 SQL 注入加固（CWE-89）：标识符校验 / ORDER BY 语法白名单 / WHERE 危险字符拒绝 / LIMIT 强转
- 备份路径穿越（CWE-22）+ 备份目录 Web 防护与随机文件名
- 审计日志敏感字段脱敏（CWE-532）
- API Key 明文暴露收敛（CWE-312）：仅创建时一次性展示
- SMTP 防内网探测（CWE-918）；Logout CSRF（CWE-352）；CSRF 中间件显式短路；开放重定向防护（CWE-601）
- hidden 类与 style.display 配对冲突全站排查；三种形态重复 class 属性清理
- SVG 上传移除（存储型 XSS，CWE-79）；时区显示规范化；上传进度条 / 图片放大首击等 UI 修复

### 📄 文档
- 前台介绍与 README 全面更新（修正 API 参数示例 format → type，补充安全特性）

### 升级指南
1. 下载下方 zip 资产覆盖部署（数据库结构运行时自动迁移，无需手动 SQL）。
2. 确保 config/ 目录可写（运行时生成 database / signing_key / credentials 配置）。
3. 重启 PHP-FPM（清 OPcache）→ 运行 doctor.php 验证后删除。
4. 如使用局域网邮件服务器，在 config/app.php 设置 'allow_private_smtp' => true。
