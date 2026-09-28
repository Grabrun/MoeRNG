# -*- coding: utf-8 -*-
"""v2.0.0-beta.4: renderHealth 输出状态徽标类（health-ok / health-bad）"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\public\js\app.js"
with io.open(P, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = "    const badge = function(ok) { return ok ? '[ OK ]' : '[待修复]'; };".replace('\n', nl)
new = ("    // v2.0.0-beta.4: 结果徽标带状态类（CSS 提供绿/红 pill）\n"
       "    const badge = function(ok) { return '<span class=\"' + (ok ? 'health-ok' : 'health-bad') + '\">' + (ok ? '[ OK ]' : '[待修复]') + '</span>'; };").replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] badge anchor not found')
t = t.replace(old, new, 1)
with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] app.js: renderHealth 徽标类已加')
