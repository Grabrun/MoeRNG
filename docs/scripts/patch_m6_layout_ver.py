# -*- coding: utf-8 -*-
"""修正 layout_contract_test.js 版本断言：写死 beta.2 → 语义断言 v2 beta 线（版本演进不红）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\.dsh\layout_contract_test.js"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

old = "ok('APP_VERSION 为 2.0.0-beta.2（v2 线迭代，取消兼容 → 主版本升 2，破坏性变更线）', ver === '2.0.0-beta.2', ver);"
new = "ok('APP_VERSION 为 v2 线（取消兼容 → 主版本 2，破坏性变更线）', /^2\\.0\\.0-beta\\.\\d+/.test(ver), ver);"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] layout_contract_test.js 版本断言已语义化')
else:
    print('[SKIP] 目标断言未命中（可能已修正）')
