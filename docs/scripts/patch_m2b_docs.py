# -*- coding: utf-8 -*-
"""docs.php 补充空库行为说明（修正缩进锚点）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\views\docs.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "该尺寸尚未生成时按 <code>请求尺寸 → md → 原图</code> 回退（绝不返回 404），\n"
    "                                    响应里的 <code>size</code> 字段如实回报<strong>实际生效</strong>的尺寸，回退也能被察觉</td>"
)
new = (
    "该尺寸尚未生成时按 <code>请求尺寸 → md → 原图</code> 回退（绝不返回 404），\n"
    "                                    响应里的 <code>size</code> 字段如实回报<strong>实际生效</strong>的尺寸，回退也能被察觉。\n"
    "                                    图库或所选分类为空时返回 <code>404</code> 与 JSON 错误体（<code>message</code> 含中文引导，<code>error</code> 保持英文机器码）</td>"
)
if old.replace('\n', nl) not in t:
    raise SystemExit('[FAIL] docs.php 锚点')
t = t.replace(old.replace('\n', nl), new.replace('\n', nl), 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] docs.php 空库说明')
