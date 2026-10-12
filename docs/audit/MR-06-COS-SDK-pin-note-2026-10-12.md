# MR-06 · COS SDK 钉 tag 说明（2026-10-12）

## 状态

**受环境限制未修**（本机无 composer / PHP CLI，无法安全地把 `src/sdk/cos` 重新钉到官方发布 tag 并重生成 vendor 树）。

## 现状（已核查）

- `src/sdk/cos/vendor/composer/installed.php`：根包 `qcloud/cos-sdk-v5` 为
  `pretty_version => dev-master`，`aliases => 2.4.x-dev`，`reference =>
  19b939580482d6e03f2e60ce8ef8b5b3aaf553fa`（dev 分支 commit，非发布 tag）。
- 次级依赖均为 tag 化（guzzle 7.15.2 / promises 2.5.1 / psr7 2.13.0 等）。
- `src/sdk/cos/composer.lock` 存在，但内容为打包时生成的依赖锁，未记录
  根包对应哪个官方 tag。
- 厂商 SDK 自带 CHANGELOG 已到 **2.6.17**，而本树别名仍为 2.4.x-dev ——
  该 dev 分支线明显滞后于当前发布线，直接重拉 dev-master 会引入未经
  对照的代码差异，因此禁止离线改写元数据冒充已钉 tag。

## 风险（来自深度审计报告 MR-06）

- COS SDK 跟随 dev 分支语义移动；未来重新打包若不取同 SHA/tag，可能静默
  引入未评审代码；dev 分支不享受官方安全公告/版本号对照。

## 修复动作（待环境具备后执行，写入路线图 MR-06）

1. 在有 composer 的机器上执行（对 `src/sdk/cos` 根目录）：
   `composer require qcloud/cos-sdk-v5:<最新稳定 tag>`（建议 ^2.6 线，如 2.6.17），
   或按当前 commit `19b939580482d6e03f2e60ce8ef8b5b3aaf553fa` 反查其对应官方 tag 后钉之。
2. 提交 `composer.lock`（记录根包 tag）。
3. 打包脚本记录 SDK SHA256 清单（见 MR-07/68 修复产物），供发布比对。

## 验证标准

干净环境 `composer require qcloud/cos-sdk-v5:<tag>` 后 diff installed.php 与
当前 vendor 文件树；发布 zip 内 SDK 树 SHA256 与清单一致。
