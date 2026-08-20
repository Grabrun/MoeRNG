## v1.2.1-beta.1

**版本概述**：安全加固与稳定性修复版。基于两轮安全审计（2026-08-14 黑盒 + 2026-08-20 白盒/黑盒，综合评分 A+）完成全部应用层修复：会话加固、限流键可信化、签名密钥独立化（文件存储，与数据库完全隔离）、fail-closed 限流、安全响应头等；同时修复本地图片加载回归、移动端布局与导航等问题。

### 🔒 安全增强（Security）

- **会话加固**：Cookie 补 HttpOnly + SameSite=Lax + Secure（HTTPS/代理感知）、`session.use_strict_mode`、登录后 `session_regenerate_id(true)`（防会话固定）
- **限流键可信化**：`Request::resolveIp()` 不再信任 X-Forwarded-For，只用 REMOTE_ADDR（API 限流 + 登录锁定键同时受益）
- **签名密钥独立化**：HMAC 签名密钥改用独立随机密钥，文件存储（config/signing_key.php）——**不派生自 DB 密码、不存储在 DB**，DB 泄露不影响签名安全
- **限流 fail-closed**：限流器目录不可用时拒绝请求而非放行（登录锁定保持有效）
- **安全响应头全站**：CSP / X-Frame-Options DENY / X-Content-Type-Options nosniff / Referrer-Policy / Permissions-Policy / X-Robots-Tag
- **信息泄露收敛**：`/api/v1/stats` 移除 version + storage_driver；首页 HTML 注释清理版本号
- **security.txt**：新增 `/.well-known/security.txt`（专用安全邮箱）
- **登录爆破防护**（已有）：失败锁定 + 可选验证码 + 审计日志

### ✨ 新功能（Features）

- **移动端汉堡导航**：5 项导航在移动端收进 `details` 折叠菜单（纯 HTML，无 JS 依赖），header 不再遮挡内容
- **HEAD 路由支持**：动态路由 HEAD 按 GET 分发（健康检查不再 404）
- **doctor.php 签名密钥自检**：round-trip + 稳定性检查，`SECRET CHANGES PER CALL` 直接定位 config/ 不可写

### 🐛 Bug 修复（Bug Fixes）

- **本地图片加载失败（严重）**：签名密钥生成回归——`file_put_contents` 失败返回 false 不抛异常导致每次请求新密钥，签名全失效；已修复（检查返回值 + 确定性回退）
- **移动端 hero 布局**：统计数字三列挤压 → 竖排 + 字号/间距适配
- **banner 显示**：宽高比锁定（`aspect-ratio: 1400/466`），修复压扁与上下留白；桌面加宽至 880px

### 🧹 工程整理（Chores）

- **tools/ 目录整理**：一次性脚本（21 个）与历史 notes（8 个）归档至 archive/，顶层只留活跃工具
- **根目录整理**：品牌素材归入 `assets/`（gitignore 锚定根级，不误伤 src/assets）

### 升级指南（Upgrade Guide）

1. 下载 zip 覆盖部署（项目根 doc-root），重启 PHP-FPM。
2. 首次访问触发签名密钥生成：`config/signing_key.php` 自动创建（config/ 需可写）；若曾部署过旧版，文件密钥保持有效，链接不中断。
3. 运行 doctor.php：新增「Signing key file」与「Signing key stable + round-trip」检查应为绿色。
4. 验证本地图片加载、移动端导航（汉堡菜单）、登录流程。
5. 验证后删除 doctor.php。
