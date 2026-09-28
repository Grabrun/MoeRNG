# -*- coding: utf-8 -*-
"""M2 收尾：style.css 统一 LF 行尾（配合 .gitattributes eol=lf）+ 最后瘦身"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\public\css\style.css"
with io.open(P, encoding='utf-8', newline='') as f:
    t = f.read()

# 1. 统一 LF（token 块已是 LF，其余 CRLF）
t = t.replace('\r\n', '\n')
nl = '\n'

# 2. token 块注释行（晴空画册说明已写在文件头注释）
t = t.replace('    /* 晴空画册（M2）—— 暖米白浅色 / 炭紫黑深色，珊瑚粉 + 鼠尾草青 + 蜜橘；圆角收敛、去霓虹 */\n', '')
print('[OK] token 注释精简')

# 3. reduced-motion 四块合并为一
b2_old = '@media (prefers-reduced-motion: reduce) {\n    .reveal { opacity: 1; transform: none; transition: none; }\n    .lightbox .lb-image, .lightbox.active, .toast { animation: none; }\n    * { scroll-behavior: auto !important; }\n}'
b2_new = '@media (prefers-reduced-motion: reduce) {\n    .reveal { opacity: 1; transform: none; transition: none; }\n    .lightbox .lb-image, .lightbox.active, .toast { animation: none; }\n    * { scroll-behavior: auto !important; }\n    .row-highlight { animation: none; outline: 1px solid var(--primary); outline-offset: -2px; }\n    *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }\n    .gacha-flash, .gacha-pop { animation: none; }\n}'
if b2_old in t:
    t = t.replace(b2_old, b2_new)
    print('[OK] reduced-motion 主块并入补充规则')
else:
    print('[FAIL] reduced-motion 主块锚点')
for pat in ['.row-highlight { animation: none; outline: 1px solid var(--primary); outline-offset: -2px; }',
            '*, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }',
            '.gacha-flash, .gacha-pop { animation: none; }']:
    head = '@media (prefers-reduced-motion: reduce) {\n    %s\n}' % pat
    if head in t:
        t = t.replace(head, '')
        print('[OK] 删独立 reduced-motion 块: %s' % pat[:40])

# 4. pagination transition 并入基础
t = t.replace('\n.pagination a { transition: all var(--transition); }', '')
print('[OK] 删重复 pagination transition')

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
size = len(t.encode('utf-8'))
print('[DONE] style.css（LF）字节数 = %d（预算 92160）' % size)
