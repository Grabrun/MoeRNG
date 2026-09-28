# -*- coding: utf-8 -*-
import io
p = r"E:\Projects\DouBao\MoeRNG\design\m2-preview\home-preview.html"
t = io.open(p, encoding='utf-8').read()
t2 = t.replace('data-theme="light"', 'data-theme="dark"')
t2 = t2.replace('<title>M2 晴空画册 · 前台首页预览</title>', '<title>M2 晴空画册 · 前台首页预览（深色）</title>')
io.open(r"E:\Projects\DouBao\MoeRNG\design\m2-preview\home-preview-dark.html", 'w', encoding='utf-8', newline='').write(t2)
print('dark preview created')
