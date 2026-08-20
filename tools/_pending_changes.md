# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.0-beta.4（2026-08-13 03:34 发布）

## 累计变更（自 v1.2.0-beta.4 起）


### 🔒 安全修复（2026-08-14 审计报告）

- **Cookie 安全属性**：会话 Cookie 加 HttpOnly + SameSite=Lax + Secure（HTTPS/代理感知），session.use_strict_mode 开启
- **XFF 限流绕过**：Request::resolveIp() 不再信任 X-Forwarded-For（API 限流与登录锁定键改用 REMOTE_ADDR）
- **会话固定**：登录成功后 session_regenerate_id(true)
- **安全响应头**：全站 CSP（兼容内联脚本）/ X-Frame-Options DENY / nosniff / Referrer-Policy / Permissions-Policy / X-Robots-Tag
- **信息泄露**：/api/v1/stats 移除 version + storage_driver
- **HEAD 支持**：动态路由 HEAD 按 GET 处理（健康检查不再 404）
- **security.txt**：新增 .well-known/security.txt
- 登录爆破防护（RateLimiter+锁定+captcha）确认已实现 ✓

_（继续迭代积累）_

---

_（本清单由本地迭代维护，发版时清空）_
