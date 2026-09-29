# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.9 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

# bootstrap.php 版本
p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.8')"
new = "define('APP_VERSION', '2.0.0-beta.9')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.9')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

# CHANGELOG 顶部条目
p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.9] - 2026-09-29\n"
    "\n"
    "### 前端性能优化（不影响功能）\n"
    "\n"
    "- P1 零感知层：字体 preconnect ×2（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com）与 dns-prefetch；\n"
    "  .htaccess 静态资源长缓存（public, max-age=31536000, immutable，ASSET_VER 指纹安全，Apache 对齐 nginx）\n"
    "- P2 渲染层：字体 CSS 异步化（media=print + CSP nonce 合规脚本立即切回 all，display=swap 兜底无空白）\n"
    "- P3 结构性：前台 JS 拆分 front.js（24.5KB 子集：toast/copyText/API 测试/随机图预览/reveal/统计滚动/动态样式），\n"
    "  前台 JS 传输 157KB→56KB（gzip 后约省 30KB+）；后台 app.js 保持原样零回归\n"
    "- 维护：layout_contract 版本断言语义化（v2 beta 线，不再写死 beta.2）；新增 docs/scripts/verify_front_runtime.js\n"
    "  （front.js 前台 DOM 运行时等价验证）；新增 docs/scripts/patch_m6_perf.py（本次变更的可复现脚本）\n"
    "- 回归：syntax 2912/2912、design_contract 63、api_contract 67、hero_stats 36、perf_contract 74、\n"
    "  layout_contract 125、page/click/xref/ref 全绿、front.js 运行时链零异常\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.8] - 2026-09-29"
if '2.0.0-beta.9' in c:
    print('[SKIP] CHANGELOG 已含 beta.9')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.9 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
