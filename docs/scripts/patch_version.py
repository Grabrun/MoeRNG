# -*- coding: utf-8 -*-
"""v2.0.0-beta.3: bootstrap.php 版本 bump"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\bootstrap.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "// v2.0.0-beta.2: 前台服务可用性实装（近 7 天 API 请求成功率 + api_stats.fail 列）。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.2');\n"
    "}"
).replace('\n', nl)
new = (
    "// v2.0.0-beta.2: 前台服务可用性实装（近 7 天 API 请求成功率 + api_stats.fail 列）。\n"
    "// v2.0.0-beta.3: 图片/读取架构审计 —— finfo 提循环、大图流式下载、基线余量常量化、删死函数 e()。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.3');\n"
    "}"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] bootstrap.php version anchor not found')
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] bootstrap.php: APP_VERSION=2.0.0-beta.3')
