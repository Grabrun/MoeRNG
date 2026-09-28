# -*- coding: utf-8 -*-
"""v2.0.0-beta.2: bootstrap.php 版本 bump"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\bootstrap.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

old = (
    "// Release version — surfaced in the footer and the /api/v1/stats endpoint.\n"
    "// v2.0.0-beta.1: 取消 v1.5.0 的兼容与迁移（唯一 v2 布局 + storage/uploads 媒体根）。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.1');\n"
    "}"
).replace('\n', nl)
new = (
    "// Release version — surfaced in the footer and the /api/v1/stats endpoint.\n"
    "// v2.0.0-beta.1: 取消 v1.5.0 的兼容与迁移（唯一 v2 布局 + storage/uploads 媒体根）。\n"
    "// v2.0.0-beta.2: 前台服务可用性实装（近 7 天 API 请求成功率 + api_stats.fail 列）。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.2');\n"
    "}"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] bootstrap.php version anchor not found')
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] bootstrap.php: APP_VERSION=2.0.0-beta.2')
