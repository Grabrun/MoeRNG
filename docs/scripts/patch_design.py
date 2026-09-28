# -*- coding: utf-8 -*-
"""design_contract_test.js：面板计数断言适配 v2.0.0（layout 面板已删）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\.dsh\design_contract_test.js"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

def rep(old, new, label):
    global t
    if old not in t:
        raise SystemExit(f"[FAIL] {label}: anchor not found")
    t = t.replace(old, new, 1)
    print(f"[OK] {label}")

rep(
    "  // 10d. 批处理面板：四个统一\n"
    "  ok('4 个面板都挂了 .batch-panel', (SET.match(/card mb-3 batch-panel/g) || []).length === 4);",
    "  // 10d. 批处理面板：统一（v2.0.0: layout 面板移除后为 3 个）\n"
    "  ok('3 个面板都挂了 .batch-panel', (SET.match(/card mb-3 batch-panel/g) || []).length === 3);",
    "design: 面板数",
)
rep(
    "  ok('每个面板都有 摘要 / 状态 / 按钮行 三段',\n"
    "     (SET.match(/class=\"batch-summary\"/g) || []).length >= 5\n"
    "     && (SET.match(/class=\"batch-actions\"/g) || []).length >= 4\n"
    "     && (SET.match(/class=\"batch-stat\"/g) || []).length >= 4);",
    "  ok('每个面板都有 摘要 / 状态 / 按钮行 三段',\n"
    "     (SET.match(/class=\"batch-summary\"/g) || []).length >= 4\n"
    "     && (SET.match(/class=\"batch-actions\"/g) || []).length >= 3\n"
    "     && (SET.match(/class=\"batch-stat\"/g) || []).length >= 3);",
    "design: 三段结构",
)
rep(
    "  ok('长说明收进折叠区（3 个 <details class=\"batch-help\">）',\n"
    "     (SET.match(/<details class=\"batch-help\">/g) || []).length === 3);",
    "  ok('长说明收进折叠区（2 个 <details class=\"batch-help\">）',\n"
    "     (SET.match(/<details class=\"batch-help\">/g) || []).length === 2);",
    "design: 折叠区",
)

with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[DONE] design_contract_test.js")
