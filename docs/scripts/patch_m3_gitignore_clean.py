# -*- coding: utf-8 -*-
""".gitignore 规则行尾注释清理：git 把行中 # 及之后当作模式内容，整行失效。
   只保留行首独立注释行；规则行纯模式。"""
import io

p = r"E:\Projects\DouBao\MoeRNG\.gitignore"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

def lines(t):
    return t.split(nl)

out = []
for ln in lines(t):
    # 清理三条带尾注释的规则行（src/config/*.php 等），规则后不得跟 # 注释
    if ln.startswith('src/config/*.php'):
        out.append('src/config/*.php')
        continue
    if ln.startswith('src/config/database.php'):
        out.append('src/config/database.php')
        continue
    if ln.startswith('src/config/signing_key.php'):
        out.append('src/config/signing_key.php')
        continue
    if ln == 'probe_ignored_file.txt':
        continue  # 移除探针
    out.append(ln)

t2 = nl.join(out)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t2)
print('[OK] .gitignore 清理完成')
