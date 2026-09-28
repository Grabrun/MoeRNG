# -*- coding: utf-8 -*-
"""M2 恢复：还原瘦身时误删的规则（分页 transition/hover、模态过渡、代码块高度）"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\public\css\style.css"
with io.open(P, encoding='utf-8', newline='') as f:
    t = f.read()
t = t.replace('\r\n', '\n')

def rep(old, new, tag):
    global t
    if old not in t:
        print('[FAIL] %s' % tag)
        return
    t = t.replace(old, new, 1)
    print('[OK] %s' % tag)

# 1. 分页：还原 transition 与 hover 上浮（用户点名不可改）
rep('.pagination a:hover { border-color: var(--primary); color: var(--primary); }',
    '.pagination a { transition: all var(--transition); }\n.pagination a:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }',
    '分页 hover 上浮 + transition 还原')

# 2. 模态过渡还原
rep('.modal-overlay.active { display: flex; }',
    '.modal-overlay { transition: opacity 0.18s ease; }\n.modal-overlay.active { display: flex; }',
    '模态过渡还原')

# 3. 代码块高度限制还原（.copy-wrap pre）
rep('.copy-wrap { position: relative; }',
    '.copy-wrap { position: relative; }\n.copy-wrap pre { max-height: 320px; overflow: auto; }',
    '代码块高度限制还原')

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[DONE] bytes =', len(t.encode('utf-8')))
