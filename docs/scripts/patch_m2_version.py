# -*- coding: utf-8 -*-
"""M2：bootstrap.php 版本 bump 2.0.0-beta.4 → 2.0.0-beta.5"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\bootstrap.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "// v2.0.0-beta.4: 后台设置排版与 UI 优化 —— 标签行 pill 化、分组卡标题强调条、设置项 hover、健康结果状态徽标、保存提示警告色。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.4');\n"
    "}"
).replace('\n', nl)
new = (
    "// v2.0.0-beta.4: 后台设置排版与 UI 优化 —— 标签行 pill 化、分组卡标题强调条、设置项 hover、健康结果状态徽标、保存提示警告色。\n"
    "// v2.0.0-beta.5: M2 晴空画册视觉重设计 —— 暖米白/炭紫黑双主题 token、珊瑚粉+鼠尾草青+蜜橘、思源宋体 display、思源黑体正文、圆角收敛去霓虹、前台 hero kicker、CSS 90KB 预算瘦身合并、CSP font-src 放行。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.5');\n"
    "}"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] version anchor not found')
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] bootstrap.php: APP_VERSION=2.0.0-beta.5')
