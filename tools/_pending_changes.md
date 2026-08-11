# 待发版变更清单（本地迭代累积区）

> 本地迭代规则（用户规则 2026-08-11）：同一版本号迭代，每次输出 zip 但不发布 GitHub；
> 本文件记录自上次正式发布（GitHub Release）以来的累计变更，发版时写入 CHANGELOG + Release Notes 并清空。

## 上次发布：v1.2.0-beta.1（2026-08-11 12:44 发布）

## 累计变更（自 v1.2.0-beta.1 起）


### ⬆️ 功能增强（Enhancements）

- **存储管理表单改为服务商驱动**：新增/编辑存储实例共用同一表单，选择服务商后字段自动适配——
  - 字段显隐跟随服务商（又拍云/OBS 隐藏 region；endpoint 仅 OBS 显示且必填）
  - 标签切换为各家语义（又拍云：服务名/操作员名/操作员密码；七牛：区域/空间名）
  - 占位符给出真实示例（COS 需带 APPID、七牛区域代码等），必填项红色星号跟随


### 🐛 Bug 修复（Bug Fixes）

- **存储管理**：修复添加又拍云 USS 实例被拒的问题——提交校验（前端 + 后端）仍用统一规则（强制 Region 必填），改为按服务商校验必填字段：又拍云（AccessKey/SecretKey/Bucket）、OBS（AccessKey/SecretKey/Bucket/Endpoint）、其余（AccessKey/SecretKey/Region/Bucket）


- **图片管理**：修复后台图片 hover 时「放大查看」与「复制链接」按钮重叠——全局 .copy-btn 的 absolute 定位在 .image-item 内脱离了 flex 流，重置为 static + 按钮尺寸 30×30 + gap 8px


- **图片上传**：修复进度条百分比不显示（display:block 覆盖 flex 布局 → 改 flex）；上传 100% 后后端保存期显示「正在保存…」+ 进度条脉冲动画，不再干等

_（继续迭代积累）_

---

_（本清单由本地迭代维护，发版时清空）_
