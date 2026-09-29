# -*- coding: utf-8 -*-
"""MoeRNG 前端性能优化（v2.0.0-beta.9）：
P1 preconnect + .htaccess 缓存头；P2 字体 CSS 异步化（CSP nonce 合规）；P3 front.js 拆分。
幂等：重复运行不破坏既有结果。
"""
import io, os

ROOT = r"E:\Projects\DouBao\MoeRNG"

def read(p):
    with io.open(os.path.join(ROOT, p), encoding='utf-8') as f:
        return f.read()

def write(p, t):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8', newline='') as f:
        f.write(t)

def rlines(p):
    with io.open(os.path.join(ROOT, p), encoding='utf-8') as f:
        return f.readlines()

# =====================================================================
# 1) front.js 生成：从 app.js 按 1-indexed 行区间精确切片（行为等价）
# =====================================================================
app = rlines('src/public/js/app.js')
slices = [
    (1, 23, 'Toast'),
    (113, 128, 'copyText'),
    (131, 309, 'initApiTester'),
    (1267, 1285, 'initCopyButtons'),
    (1363, 1381, 'parseJsonResponse'),
    (2351, 2359, 'UX_ICON'),
    (2464, 2480, 'initReveal'),
    (2483, 2612, 'initRandomDemo'),
    (2653, 2679, 'initStatCount'),
    (2684, 2697, 'applyDynamicStyles'),
]
header = """// MoeRNG - Front-site JavaScript (v2.0.0-beta.9 性能优化拆分)
//
// 本文件是 app.js 的前台子集，只包含前台页面（home/gallery/docs/tester/about）
// 实际使用的功能；后台管理页仍加载完整的 app.js（本文件不与之同页加载，无冲突）。
// 来源保持与 app.js 逐字节一致（由 docs/scripts/patch_m6_perf.py 按行切片生成），
// 任何前台交互的修改应同步到 app.js 或回归本文件。
"""
parts = [header]
for s, e, tag in slices:
    parts.append('\n// ---------- %s (app.js L%d-L%d) ----------\n' % (tag, s, e))
    parts.append(''.join(app[s - 1:e]))
parts.append("""
// ---------- Init on DOM ready (front-site subset) ----------
document.addEventListener('DOMContentLoaded', function() {
    initThemeToggle();   // defined in helpers.js (loaded first)
    initApiTester();
    initCopyButtons();
    initReveal();
    initRandomDemo();
    initStatCount();
    applyDynamicStyles();
});
""")
front_js = ''.join(parts)
write('src/public/js/front.js', front_js)
print('[OK] front.js %d 行 / %d 字节' % (front_js.count('\n'), len(front_js.encode('utf-8'))))

# =====================================================================
# 2) front_header.php：preconnect + 字体异步化（CSP nonce 合规）
# =====================================================================
fh = read('src/views/partials/front_header.php')
old_link = ('    <link rel="stylesheet" href="https://miaoda.feishu.cn/fonts/css2?'
            'family=Noto+Serif+SC:wght@600;700&family=Noto+Sans+SC:wght@400;500;700&display=swap">')
new_block = """    <link rel="preconnect" href="https://miaoda.feishu.cn" crossorigin>
    <link rel="preconnect" href="https://sf3-scmcdn-cn.feishucdn.com" crossorigin>
    <link rel="dns-prefetch" href="https://miaoda.feishu.cn">
    <link rel="dns-prefetch" href="https://sf3-scmcdn-cn.feishucdn.com">
    <!-- v2.0.0-beta.9: 字体 CSS 异步化 —— media=print 使其不阻塞首屏渲染，
        紧随其后的 nonce 脚本立即切回 all（CSP script-src 禁 inline onload，此写法合规）。
        display=swap 保证字体到达前以系统字体渲染，无空白。 -->
    <link rel="stylesheet" href="https://miaoda.feishu.cn/fonts/css2?family=Noto+Serif+SC:wght@600;700&family=Noto+Sans+SC:wght@400;500;700&display=swap" id="font-css" media="print">
    <script<?= CspNonce::attr() ?>>
    (function(){var l=document.getElementById('font-css');if(l){l.media='all';}})();
    </script>"""
if old_link in fh:
    fh = fh.replace(old_link, new_block, 1)
    write('src/views/partials/front_header.php', fh)
    print('[OK] front_header.php：preconnect + 字体异步化')
else:
    print('[SKIP] front_header.php 目标行未命中（可能已修改）')

# =====================================================================
# 3) admin/layout.php：同 preconnect + 字体异步化
# =====================================================================
al = read('src/views/admin/layout.php')
if old_link in al:
    al = al.replace(old_link, new_block, 1)
    write('src/views/admin/layout.php', al)
    print('[OK] admin/layout.php：preconnect + 字体异步化')
else:
    print('[SKIP] admin/layout.php 目标行未命中（可能已修改）')

# =====================================================================
# 4) .htaccess：静态资源长缓存（Apache 部署对齐 nginx 策略）
# =====================================================================
ht = read('src/.htaccess')
cache_marker = '# MoeRNG static asset long-cache'
if cache_marker in ht:
    print('[SKIP] .htaccess 缓存段已存在')
else:
    cache_block = """
# -----------------------------------------------------------------------------
# v2.0.0-beta.9 前端性能优化: 静态资源长缓存。
# 所有 URL 均带 ASSET_VER 指纹（APP_VERSION.mtime），内容不可变，可安全 immutable；
# 图片缩略图/原图为生成后不可变文件。Apache 部署补齐，nginx 见 nginx.conf.example。
# 依赖 mod_headers；模块缺失时静默跳过，不影响站点。
# -----------------------------------------------------------------------------
<IfModule mod_headers.c>
    <FilesMatch "\\.(css|js|mjs|webp|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
"""
    # 追加到文件末尾（保留原换行风格）
    nl = '\r\n' if '\r\n' in ht else '\n'
    if not ht.endswith('\n'):
        ht += nl
    write('src/.htaccess', ht + cache_block.replace('\n', nl))
    print('[OK] .htaccess：静态长缓存')

# =====================================================================
# 5) front_footer.php：helpers.js + front.js（替换 app.js）
# =====================================================================
ff = read('src/views/partials/front_footer.php')
if '/public/js/front.js' in ff:
    print('[SKIP] front_footer.php 已引用 front.js')
else:
    ff = ff.replace(
        '    <script src="/public/js/app.js?v=<?= ASSET_VER ?>"></script>',
        '    <script src="/public/js/front.js?v=<?= ASSET_VER ?>"></script>', 1)
    write('src/views/partials/front_footer.php', ff)
    print('[OK] front_footer.php：切换 front.js')

print('[DONE]')
