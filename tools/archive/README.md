# tools/archive

历史一次性脚本的归档区。这些脚本在特定迭代中完成使命后移入此处，
**不再维护**；如需复用请基于 git 历史与当时上下文判断。

## scripts/

按主题归档的一次性迁移 / 修复脚本（全部针对 `src/` 源码，跑过即完成使命）：

| 系列 | 文件 | 用途（对应迭代） |
|------|------|------------------|
| CSP 内联样式迁移 | `_csp_apply` `_csp_utils` `_home_csp` `_dash_csp` `_dash_csp2` `_nonce_fix` | inline style → class 迁移、CSP nonce 修复（v1.2.1） |
| 重复 class 去重 | `_dedupe_class` `_dedupe_class2` `_dedupe_class3` | 三种形态：同行 / 跨行 / 隔属性（v1.2.1） |
| 资源版本号 | `_asset_ver` `_asset_ver2` `_asset_ver3` `_asset_ver_fix` | 视图资源 URL 加 `?v=APP_VERSION`（v1.2.0） |
| 交互改造 | `_inline_conv` `_confirm_conv` `_confirm_js` `_guard_patch` `_edit_btn` `_edit_user` | inline handler → data-* 委托、确认弹窗、编辑入口（v1.2.x） |
| 安全修复 | `_install_lock` | install 重装漏洞 P0 修复（v1.2.1-beta.2） |
| 其它 | `_count_cache` `_dash_n1` | 统计缓存、仪表盘局部修复 |

> 注意：`_dedupe_class3.py` 含跨盘符 / data-* 属性保护逻辑，若将来再做
> class 合并，优先参考它的负向后顾写法。

## notes/

历史版本的 Release Notes 草稿与发布记录。
