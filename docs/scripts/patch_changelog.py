# -*- coding: utf-8 -*-
"""v2.0.0-beta.2: CHANGELOG 条目"""
import io

p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "## [2.0.0-beta.1] - 2026-09-28\n"
    "\n"
    "> **破坏性变更**"
).replace('\n', nl)
new = (
    "## [2.0.0-beta.2] - 2026-09-28\n"
    "\n"
    "> 前台「服务可用性」实装：从静态字面量 99.9% 变为近 7 天 API 请求成功率（SLA 口径），随真实请求流量变化。\n"
    "\n"
    "### ✨ 新功能\n"
    "\n"
    "- **服务可用性实装**（首页 hero 第三个统计）：`api_stats` 新增 `fail` 列；`api.php` 未捕获异常（5xx）单独记失败（`Stats::bumpFail`），正常响应（含 4xx 业务校验失败——服务正常响应即「可用」）仍走成功计数；`Stats::availability()` 计算近 7 天成功率，首页动态渲染\n"
    "- **无样本兜底**：新装 / 尚无流量时 `availability = null`，前台显示默认宣传值 99.9%；有样本后显示真实值（1 位小数，如 100.0% / 97.3%）\n"
    "- 首页统计注释与 hero_stats 契约测试同步（第三个数字为动态值语义，`data-count` 仍仅前两项）\n"
    "\n"
    "### 🔧 维护\n"
    "\n"
    "- 深度审计（2026-09-28）：`Application.php` backfill 改为从默认 profile 推导（移除旧设置键 `storage_driver` / `storage_s3_provider` 读取）；`helpers.php` 删除死调试函数 `dd()`；全量 19 项 harness + 语法 2912/2912 全绿\n"
    "- `SCHEMA_VERSION` 升至 `2026-09-28-2`（api_stats.fail 幂等迁移：SHOW COLUMNS probe → ALTER，权限被拒时降级不破坏站点）\n"
    "\n"
    "### ✅ 回归\n"
    "\n"
    "- hero_stats_contract（35）/ layout（125）/ thumbs（118）/ design（63）/ perf（74）/ queue（143）/ api（67）/ convert（71）/ memory_guard（46）/ click_matrix / page_matrix / scope / click / ref_check / xref_audit / php_undef / sdk_integrity / verify_autoload / model_fillable 全部通过\n"
    "\n"
    "## [2.0.0-beta.1] - 2026-09-28\n"
    "\n"
    "> **破坏性变更**"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] CHANGELOG anchor not found')
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] CHANGELOG: v2.0.0-beta.2 条目')
