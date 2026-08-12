# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.0-beta.3（2026-08-12 11:52 发布）

## 累计变更（自 v1.2.0-beta.3 起）


- **默认 Logo + Favicon**：新增多尺寸像素艺术 logo（32/64/128/256 + 1024 中间档），1024 整数倍 NEAREST 缩放保留像素感；favicon.ico 多尺寸（16+32）；home.php <head> 接入 favicon；默认 logo_url 指向 /assets/logo.png（HomeController 兜底 + 安装/后台 default）


- **默认 Logo**：修复 doc-root=项目根 部署下 /assets/logo.png 404——logo/favicon 双位置放置（src/assets/ + src/public/assets/、src/favicon.ico + src/public/favicon.ico），兼容 public/ 与项目根两种 doc-root

_（继续迭代积累）_

---

_（本清单由本地迭代维护，发版时清空）_
