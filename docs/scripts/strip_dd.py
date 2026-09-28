# -*- coding: utf-8 -*-
"""删除 helpers.php 的 dd() 调试函数（全库零调用，深度审计无残留）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\app\helpers.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

old = (
    "\nif (!function_exists('dd')) {\n"
    "    function dd(mixed ...$args): never {\n"
    "        echo '<pre style=\"background:#1a1a2e;color:#e8e8f0;padding:16px;border-radius:8px;font-size:13px;line-height:1.6;overflow:auto;max-height:80vh;\">';\n"
    "        foreach ($args as $arg) {\n"
    "            echo htmlspecialchars(print_r($arg, true));\n"
    "        }\n"
    "        echo '</pre>';\n"
    "        exit;\n"
    "    }\n"
    "}\n"
)
if old not in t:
    raise SystemExit("[FAIL] dd() anchor not found")
t = t.replace(old, "\n", 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[OK] dd() 已删除")
