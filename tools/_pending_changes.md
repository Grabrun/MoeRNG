# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.1.1-beta.5（2026-08-11 11:46 发布）

## 累计变更（自 v1.1.1-beta.5 起）

### 🐛 Bug 修复（Bug Fixes）

- **doctor.php**：修复「Config dir not web-exposed」在宝塔防护环境下的误报——HTTP 200 不再直接判定为密码泄露，改为检查响应体关键词：宝塔/open_basedir/WAF 拦截页（禁止执行、防跨站、blocked、forbidden 等）→ OK；含 `<?php` / DB_HOST / DB_PASS 等 PHP 凭据特征 → FAIL；两者都不匹配 → WARN 并显示 body 摘要人工确认


### 🚀 新功能（New Features）

- **对象存储接入第 5、6 家**：又拍云 USS + 七牛云 Kodo（全部官方 SDK）
  - sdk/upyun/：upyun/sdk 官方源码（Guzzle/psr7 复用 COS vendor，已做 psr7 v1→v2 适配补丁）
  - sdk/qiniu/：qiniu/php-sdk 官方源码（自实现 curl 无 Guzzle）+ 捆绑最小 MyCLabs Enum
  - 新驱动：UpyunSdkDriver（service+operator+password）、QiniuSdkDriver（ak+sk+bucket+region z0-z3/as0/na0）
  - S3Driver 委托 / 存储管理页服务商下拉 / 凭据校验 / doctor SDK 检查 全部接入

---

_（本清单由本地迭代维护，发版时清空）_
