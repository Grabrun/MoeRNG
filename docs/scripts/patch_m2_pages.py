# -*- coding: utf-8 -*-
"""M2 页面层：字体引入（前台/后台）+ CSP font-src 放行 + 前台 hero kicker"""
import io

def load(p):
    with io.open(p, encoding='utf-8', newline='') as f:
        t = f.read()
    return t, ('\r\n' if '\r\n' in t else '\n')

def save(p, t, nl):
    t = t.replace('\r\n', '\n').replace('\n', nl)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)

FONT_LINK = '<link rel="stylesheet" href="https://miaoda.feishu.cn/fonts/css2?family=Noto+Serif+SC:wght@600;700&family=Noto+Sans+SC:wght@400;500;700&display=swap">'

# ── 1. 前台 head：字体 link 在 style.css 前 ──
p = r"E:\Projects\DouBao\MoeRNG\src\views\partials\front_header.php"
t, nl = load(p)
anchor = '<link rel="stylesheet" href="/public/css/style.css?v=<?= ASSET_VER ?>">'
if FONT_LINK in t:
    print('[SKIP] front_header 已有字体 link')
elif anchor in t:
    t = t.replace(anchor, FONT_LINK + nl + '    ' + anchor, 1)
    save(p, t, nl)
    print('[OK] front_header 字体 link')
else:
    print('[FAIL] front_header 锚点')

# ── 2. 后台 head：字体 link ──
p = r"E:\Projects\DouBao\MoeRNG\src\views\admin\layout.php"
t, nl = load(p)
anchor = '<link rel="stylesheet" href="/public/css/style.css?v=<?= ASSET_VER ?>"></head>'
if FONT_LINK in t:
    print('[SKIP] layout 已有字体 link')
elif anchor in t:
    t = t.replace(anchor, FONT_LINK + nl + '    ' + anchor, 1)
    save(p, t, nl)
    print('[OK] layout 字体 link')
else:
    print('[FAIL] layout 锚点')

# ── 3. CSP：style-src 放行字体域名 + 新增 font-src ──
p = r"E:\Projects\DouBao\MoeRNG\src\app\Core\Application.php"
t, nl = load(p)
old = "style-src 'self' 'nonce-{$nonce}'; img-src"
new = "style-src 'self' 'nonce-{$nonce}' https://miaoda.feishu.cn; font-src 'self' https://miaoda.feishu.cn; img-src"
if old in t:
    t = t.replace(old, new, 1)
    save(p, t, nl)
    print('[OK] CSP font-src/style-src 放行')
else:
    print('[FAIL] CSP 锚点')

# ── 4. home.php：hero-left 顶部加 kicker ──
p = r"E:\Projects\DouBao\MoeRNG\src\views\home.php"
t, nl = load(p)
anchor = '<picture class="hero-banner-wrap">'
kicker = '<span class="hero-kicker">自托管 · 真随机 · 治愈感</span>'
if kicker in t:
    print('[SKIP] home 已有 kicker')
elif anchor in t:
    t = t.replace(anchor, kicker + nl + '                ' + anchor, 1)
    save(p, t, nl)
    print('[OK] home hero-kicker')
else:
    print('[FAIL] home 锚点')
