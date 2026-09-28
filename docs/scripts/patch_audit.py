# -*- coding: utf-8 -*-
"""audit 报告：死 API 项标记已收口（v2.0.0-beta.1）——两个独立行替换"""
import io

p = r"E:\Projects\DouBao\MoeRNG\docs\audit\2026-09-23-deep-audit.md"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

row1_old = (
    "| 1 | 死 API：`StorageInterface::configFields()` + 8 个驱动实现、`Controller::isPost`"
    "（Core/Controller.php:110）、`Request::isPost`（:74）、`StorageProfile::defaultDriver`"
    "（:246） | 本轮全库引用扫描：**均零调用**（`->x(` / `::x(` / callable 数组 / 路由字符串全搜） |"
)
row1_new = (
    "| 1 | ~~死 API：`StorageInterface::configFields()` + 8 个驱动实现、`Controller::isPost`"
    "（Core/Controller.php:110）、`Request::isPost`（:74）、`StorageProfile::defaultDriver`"
    "（:246）~~ → **已收口（v2.0.0-beta.1）**：全部删除，xref/ref_check 双验证零悬空 |"
    " 本轮全库引用扫描：**均零调用**（`->x(` / `::x(` / callable 数组 / 路由字符串全搜） |"
)
row3_old = (
    "| 3 | `StorageProfile::defaultDriver` 注释「used by Image::getStorageDriver」过时"
    "（该方法已不存在） | grep 无 `getStorageDriver` |"
)
row3_new = (
    "| 3 | ~~`StorageProfile::defaultDriver` 注释「used by Image::getStorageDriver」过时~~"
    " → **已收口（v2.0.0-beta.1）**：方法删除，过时注释随方法消失 | grep 无 `getStorageDriver` |"
)

for old, new, label in ((row1_old, row1_new, "row1"), (row3_old, row3_new, "row3")):
    if old not in t:
        raise SystemExit(f"[FAIL] {label} anchor not found")
    t = t.replace(old, new, 1)
    print(f"[OK] {label}")

with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[DONE] audit 死 API 已标记收口")
