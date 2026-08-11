## v1.2.0-beta.1

**版本概述**：对象存储扩展至六家——新增又拍云 USS 与七牛云 Kodo（全部官方 SDK），同时修复 doctor.php 在宝塔防护环境下的误报。

### 🚀 新功能（New Features）

- **对象存储接入第 5、6 家：又拍云 USS + 七牛云 Kodo**（全部官方 SDK，零自研签名）
  - sdk/upyun/：upyun/sdk 官方源码，psr7 v1→v2 适配后复用 COS vendor 的 Guzzle/PSR-7
  - sdk/qiniu/：qiniu/php-sdk 官方源码（自实现 curl 无 Guzzle），捆绑最小 MyCLabs Enum
  - 新增 UpyunSdkDriver（service + operator + password，无 region）与 QiniuSdkDriver（AK/SK + bucket + region z0-z3/as0/na0）
  - 存储管理页服务商下拉自动出现两家新选项，doctor 新增对应 SDK 检查项

### 🐛 Bug 修复（Bug Fixes）

- **doctor.php**：修复「Config dir not web-exposed」在宝塔/open_basedir 防护下的误报——HTTP 200 不再直接判定为密码泄露，改为检查响应体关键词（宝塔拦截页 → OK；PHP 凭据特征 → FAIL；未知 → WARN 人工确认）

### 升级指南（Upgrade Guide）

1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。
2. 重启 PHP-FPM 触发数据库自动迁移。
3. 运行 doctor.php 验证部署健康后删除。

完整变更日志请查看 [CHANGELOG.md](https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md)。
