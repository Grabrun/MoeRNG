# -*- coding: utf-8 -*-
"""thumbs_contract_test.js 第 2 节：key 约定适配 v2.0.0（唯一布局 {dir}/thumb-{size}.webp）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\.dsh\thumbs_contract_test.js"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

old = (
    "console.log('── 2. key 约定（md 走历史路径，避免孤儿文件）──');\n"
    "ok(\"md 分支返回 'thumbs/' . \\$rel（无尺寸段）\", /return 'thumbs\\/' \\. \\$rel;/.test(model));\n"
    "ok(\"其它尺寸 'thumbs/' . \\$size . '/' . \\$rel\", /return 'thumbs\\/' \\. \\$size \\. '\\/' \\. \\$rel;/.test(model));\n"
    "ok('扩展名统一替换为 .webp', model.includes(\"preg_replace('/\\\\.[a-z0-9]+$/i', '.webp', $path)\"));"
)
new = (
    "console.log('── 2. key 约定（v2.0.0 唯一布局 {dir}/thumb-{size}.webp）──');\n"
    "ok(\"thumbKey 统一返回 {dir}/thumb-{size}.webp（md 不再走历史路径）\",\n"
    "   /return \\$parts\\['dir'\\] \\. '\\/' \\. self::thumbVariant\\(\\$size\\) \\. '\\.webp';/.test(model));\n"
    "ok('非 v2 布局抛 RuntimeException（历史 v1 不再支持）',\n"
    "   /throw new \\\\RuntimeException\\([\\s\\S]{0,80}无法按 v2 布局推导缩略图键/.test(model));\n"
    "ok('扩展名固定 .webp（thumbVariant 拼后缀，不再正则替换原扩展名）',\n"
    "   /thumbVariant\\(\\$size\\) \\. '\\.webp'/.test(model) && !model.includes(\"preg_replace('/\\\\.[a-z0-9]+$/i', '.webp', $path)\"));"
)
if old not in t:
    raise SystemExit("[FAIL] thumbs 第 2 节 anchor not found")
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[OK] thumbs_contract_test.js 第 2 节已更新")
