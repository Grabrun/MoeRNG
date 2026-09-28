# -*- coding: utf-8 -*-
"""重写 .gitignore：去 BOM、shots 规则拆为注释行+规则行（尾随注释行 git 不匹配）"""
import io

P = r"E:\Projects\DouBao\MoeRNG\.gitignore"
with io.open(P, encoding='utf-8-sig', newline='') as f:
    t = f.read()

# 归一化行
nl = '\r\n'
lines = [ln.rstrip('\r\n') for ln in t.splitlines()]

out = []
for ln in lines:
    s = ln.strip()
    # 命中旧形态：尾随注释的 shots 规则 → 拆两行
    if s.startswith('design/_shots/') and '#' in s:
        comment = s[s.index('#') + 1:].strip()
        out.append('# ' + comment)
        out.append('design/_shots/')
    else:
        out.append(ln)

new = nl.join(out).rstrip() + nl
with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(new)
print('[OK] .gitignore 重写完成（UTF-8 无 BOM）')

import subprocess
r = subprocess.run(['git', 'check-ignore', '-v', 'design/_shots/settings-preview.html'],
                   cwd=r"E:\Projects\DouBao\MoeRNG", capture_output=True, text=True)
print('check-ignore:', r.stdout.strip() or '(NOT matched)', 'exit', r.returncode)
