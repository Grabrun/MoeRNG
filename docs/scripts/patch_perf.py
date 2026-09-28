# -*- coding: utf-8 -*-
"""perf_contract_test.js：/files 快路径断言更新（v2.0.0 无历史根）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\.dsh\perf_contract_test.js"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

marker = "  ok('快路径零查询（默认根 + 历史根先行）',"
i = t.find(marker)
if i == -1:
    raise SystemExit("[FAIL] marker not found")
j = t.find('));', i)
if j == -1:
    raise SystemExit("[FAIL] end not found")
old_block = t[i:j + 3]
new_block = (
    "  ok('快路径零查询（默认根先行；v2.0.0 起不再有历史根）',\n"
    "     /locateIn\\(\\[LocalDriver::defaultUploadDir\\(\\)\\], \\$relative\\)/.test(show));"
)
t = t[:i] + new_block + t[j + 3:]
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[OK] perf 快路径断言已更新")
print("old:", old_block.replace('\n', ' / '))
