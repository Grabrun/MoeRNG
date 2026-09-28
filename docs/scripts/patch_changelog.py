# -*- coding: utf-8 -*-
"""v2.0.0-beta.3: CHANGELOG 条目"""
import io

p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "## [2.0.0-beta.2] - 2026-09-28\n"
    "\n"
    "> 前台「服务可用性」实装"
).replace('\n', nl)
new = (
    "## [2.0.0-beta.3] - 2026-09-28\n"
    "\n"
    "> 全站代码检查 + 图片处理架构审计：真实性能修复 3 处 + 死代码清理；域服务抽取因 harness 契约锁定暂缓（路径已文档化）。\n"
    "\n"
    "### 🔧 维护与性能\n"
    "\n"
    "- **上传循环 finfo 提出循环**：批量上传时 fileinfo 库不再每张图重建（仅循环外打开一次）\n"
    "- **大图流式下载**：`FileController::show()` 清 gzip 输出缓冲后 `readfile` —— 此前整文件进缓冲再压缩（图片已压缩，gzip 白费 CPU 且内存翻倍）\n"
    "- **基线余量常量化**：两处裸 `16777216`（解码/转码 16 MiB 基线）收敛为 `MEMORY_BASELINE_HEADROOM`\n"
    "- **死代码清理**：helpers.php 删除与 `h()` 完全重复且全站零调用的 `e()`\n"
    "\n"
    "### 📄 文档\n"
    "\n"
    "- docs/audit/2026-09-28-image-arch-audit.md：全站检查结论 + 图片处理架构审计 + 域服务抽取边界（harness 方法体级契约锁定；建议先解耦测试再迁移，顺序与落点已给出）\n"
    "\n"
    "### ✅ 回归\n"
    "\n"
    "- 全量 19 项 harness 全绿：layout 125 / thumbs 118 / convert 71 / memory_guard 46 / queue 143 / perf 74 / api 67 / design 63 / hero 36 / click_matrix / page_matrix / ref_check / xref / php_undef / data_security / scope / click / verify_autoload；语法 2912/2912\n"
    "\n"
    "## [2.0.0-beta.2] - 2026-09-28\n"
    "\n"
    "> 前台「服务可用性」实装"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] CHANGELOG anchor not found')
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] CHANGELOG: v2.0.0-beta.3 条目')
