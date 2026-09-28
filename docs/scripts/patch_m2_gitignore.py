# -*- coding: utf-8 -*-
"""M2：.gitignore 追加 design/m2-preview/（预览自检副产品，非交付物）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\.gitignore"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

line = 'design/m2-preview/'
if line in t:
    print('[SKIP] 已存在')
else:
    t = t.rstrip() + nl + nl + line + nl
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] .gitignore 追加 m2-preview')
