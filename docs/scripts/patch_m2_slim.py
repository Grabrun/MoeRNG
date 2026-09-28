# -*- coding: utf-8 -*-
"""M2 瘦身：合并重复声明，把 style.css 拉回 90KB 预算（无语义变化）"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\public\css\style.css"
with io.open(P, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

def rep(old, new, tag, cnt=1):
    global t
    o = old.replace('\n', nl)
    n = new.replace('\n', nl)
    if o not in t:
        print('[FAIL] %s' % tag)
        return
    t = t.replace(o, n, cnt)
    print('[OK] %s' % tag)

# 1. :root 注释行（token 块已含晴空画册说明，旧头部注释重复）
rep('    /* 晴空画册（M2）—— 暖米白浅色 / 炭紫黑深色，珊瑚粉 + 鼠尾草青 + 蜜橘；圆角收敛、去霓虹 */\n', '', ':root 内部注释精简')

# 2. body::before background 缩进（8 空格 → 4 空格）
rep('        background: \n        radial-gradient(ellipse at 18% 22%',
    '    background: \n    radial-gradient(ellipse at 18% 22%', 'body::before 缩进')
rep('radial-gradient(ellipse at 82% 14%, rgba(127,184,166,0.07) 0%, transparent 52%),\n        radial-gradient',
    'radial-gradient(ellipse at 82% 14%, rgba(127,184,166,0.07) 0%, transparent 52%),\n    radial-gradient', 'body::before 缩进2')
rep('radial-gradient(ellipse at 55% 90%, rgba(232,179,94,0.05) 0%, transparent 50%);',
    'radial-gradient(ellipse at 55% 90%, rgba(232,179,94,0.05) 0%, transparent 50%);', 'noop3')

# 3. .form-control 912 并入基础（116 行 transition 扩展）
rep('    transition: border-color var(--transition);\n}\n.form-control:focus',
    '    transition: border-color var(--transition), box-shadow var(--transition);\n}\n.form-control:focus', 'form-control 基础 transition 扩展')
rep('\n.form-control { transition: border-color var(--transition), box-shadow var(--transition); }\n', '\n', '删重复 .form-control transition 行')

# 4. .stat-card hover/transition 并入基础（264-270），删 1034-1036
rep('.stat-card {\n    background: var(--bg-card); border: 1px solid var(--border);\n    border-radius: var(--radius); padding: 24px;\n    box-shadow: var(--shadow-sm); text-align: center;\n}',
    '.stat-card {\n    background: var(--bg-card); border: 1px solid var(--border);\n    border-radius: var(--radius); padding: 24px;\n    box-shadow: var(--shadow-sm); text-align: center;\n    transition: transform var(--transition), border-color var(--transition), box-shadow var(--transition);\n}\n.stat-card:hover { transform: translateY(-2px); border-color: var(--border-light); box-shadow: var(--shadow-sm); }',
    'stat-card 基础并入 hover/transition')
rep('\n.stat-card { transition: transform var(--transition), border-color var(--transition), box-shadow var(--transition); }\n.stat-card:hover { transform: translateY(-2px); border-color: var(--border-light); box-shadow: var(--shadow-sm); }\n.stat-card .stat-value { transition: color var(--transition); }\n',
    '\n.stat-card .stat-value { transition: color var(--transition); }\n', '删重复 stat-card 区块')

# 5. .stat-value tabular-nums 并入 269
rep('.stat-value { font-size: 2.2rem; font-weight: 800; color: var(--primary); font-family: var(--font-display); }',
    '.stat-value { font-size: 2.2rem; font-weight: 800; color: var(--primary); font-family: var(--font-display); font-variant-numeric: tabular-nums; }',
    'stat-value 基础并入 tabular-nums')
rep('\n.stat-value { font-variant-numeric: tabular-nums; }\n', '\n', '删重复 stat-value 行')

# 6. .table-wrap 合并（927 → 233）
rep('.table-wrap { overflow-x: auto; }',
    '.table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }', 'table-wrap 基础并入边框')
rep('\n.table-wrap { border: 1px solid var(--border); border-radius: var(--radius-sm); }', '', '删重复 table-wrap 边框行')

# 7. .settings-tab cursor 并入 1796；.settings-toolbar 冗余声明删
rep('.settings-tab{font-weight:600;border-radius:var(--radius-pill);padding:7px 16px}',
    '.settings-tab{cursor:pointer;font-weight:600;border-radius:var(--radius-pill);padding:7px 16px}', 'settings-tab 并入 cursor')
rep('\n.settings-tab { cursor: pointer; }\n', '\n', '删独立 settings-tab cursor 行')
rep('\n.settings-toolbar{flex-wrap:wrap;align-items:center}', '', '删冗余 settings-toolbar 行')

# 8. .toast 876 / 1011 box-shadow 并入 480
rep('.toast {\n    padding: 12px 20px; border-radius: var(--radius-sm);\n    background: var(--bg-card); border: 1px solid var(--border);\n    color: var(--text); font-size: 0.9rem; box-shadow: var(--shadow);\n    animation: slideIn 0.3s ease; max-width: 360px;\n}',
    '.toast {\n    position: relative; overflow: hidden;\n    padding: 12px 20px; border-radius: var(--radius-sm);\n    background: var(--bg-card); border: 1px solid var(--border);\n    color: var(--text); font-size: 0.9rem; box-shadow: var(--shadow);\n    animation: slideIn 0.3s ease; max-width: 360px;\n}',
    'toast 基础并入 position/overflow')
rep('\n.toast { position: relative; overflow: hidden; }', '', '删重复 toast 行')
rep('\n.toast { animation: toast-in 0.22s ease; box-shadow: var(--shadow); }',
    '\n.toast { animation: toast-in 0.22s ease; }', 'toast 1011 去重复 box-shadow')

# 9. 完全重复块删除
rep('.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
    '.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }', 'noop-truncate')
# 删第二个 .truncate（用 rfind 定位后手工处理：先转唯一锚点）
t = t.replace('\n.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }', '', 1)
print('[OK] 删第一个重复 .truncate')

t = t.replace('\n.progress-bar .fill.processing { animation: progress-pulse 0.9s ease-in-out infinite; }', '', 1)
print('[OK] 删第一个重复 .fill.processing')

rep('\n.empty-state .empty-icon { transition: all var(--transition); }', '', '删重复 empty-icon transition')
rep('\n.modal-overlay { transition: opacity 0.18s ease; }', '', '删重复 modal-overlay transition')
rep('\n.copy-wrap pre { max-height: 320px; overflow: auto; }', '', '删重复 copy-wrap pre')
rep('\n.pagination a:hover { transform: translateY(-1px); }', '', '删重复 pagination hover transform')
rep('.toggle input { display: none; }', '.toggle input { display: none; opacity: 0; width: 0; height: 0; }', 'toggle input 合并隐藏声明')
rep('\n.toggle input { opacity: 0; width: 0; height: 0; }', '', '删重复 toggle input')

# 10. feature-card 新块并入基础（567-578）
rep('.feature-card {\n    background: var(--bg-card); border: 1px solid var(--border);\n    border-radius: var(--radius); padding: 32px;\n    text-align: center; transition: all var(--transition);\n}',
    '.feature-card {\n    background: var(--bg-card); border: 1px solid var(--border);\n    border-radius: var(--radius); padding: 26px 28px;\n    text-align: left; transition: all var(--transition);\n}',
    'feature-card 基础并入 M2 左对齐')
rep('    width: 56px; height: 56px; margin: 0 auto 16px;\n    border-radius: 14px;',
    '    width: 56px; height: 56px; margin: 0 0 14px;\n    border-radius: 14px;', 'feature-card icon margin')
rep('\n.feature-card{text-align:left;padding:26px 28px}.feature-card .icon{margin:0 0 14px}', '', '删 M2 重复 feature-card 行')

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
size = len(t.encode('utf-8'))
print('[DONE] style.css 写入，字节数 = %d（预算 92160）' % size)
