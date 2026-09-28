# -*- coding: utf-8 -*-
"""M2 晴空画册 · Token 层落地（style.css）：换肤 + 瘦身 + 粉紫残影清理 + 前台/后台增强"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\public\css\style.css"
with io.open(P, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

def rep(old, new, tag, once=True):
    global t
    o = old.replace('\n', nl)
    n = new.replace('\n', nl)
    if o not in t:
        print('[FAIL] %s: anchor not found' % tag)
        return False
    t = t.replace(o, n, 1 if once else -1)
    print('[OK] %s' % tag)
    return True

# ── 1. :root + [data-theme="light"] 整体替换（保留变量名集合，值 = 晴空画册）──
start = t.index(':root {')
end = t.index('* { margin: 0; padding: 0; box-sizing: border-box; }')
tokens_new = (
    ":root {\n"
    "    /* 晴空画册（M2）—— 暖米白浅色 / 炭紫黑深色，珊瑚粉 + 鼠尾草青 + 蜜橘；圆角收敛、去霓虹 */\n"
    "    --primary: #F2789A; --primary-dark: #F88DA9; --primary-light: #3A2633;\n"
    "    --accent: #7FB8A6; --accent-dark: #6AA894; --accent-light: #22332E;\n"
    "    --neon: #E8B35E; --neon-cyan: #7FA8DC;\n"
    "    --bg: #211E26; --bg-card: #2C2833; --bg-card-hover: #322D3A; --bg-input: #282430;\n"
    "    --text: #F2EDF0; --text-secondary: #B9B0BD; --text-muted: #837A8C;\n"
    "    --border: #3A3543; --border-light: #332E3C;\n"
    "    --success: #6FB894; --warning: #E8B35E; --danger: #E57B7B; --info: #7FA8DC;\n"
    "    --danger-hover: #F28D8D; --warning-hover: #F0C67F;\n"
    "    --accent-ink: #14211E; --warning-ink: #3A3022;\n"
    "    --radius: 14px; --radius-sm: 10px; --radius-lg: 18px; --radius-pill: 999px;\n"
    "    --maxw: 1120px;\n"
    "    --shadow: 0 1px 3px rgba(0,0,0,.25), 0 8px 28px rgba(0,0,0,.25);\n"
    "    --shadow-sm: 0 1px 3px rgba(0,0,0,.2);\n"
    "    --shadow-floating: 0 2px 6px rgba(0,0,0,.3), 0 14px 36px rgba(0,0,0,.35);\n"
    "    --transition: 0.2s ease;\n"
    "    --font: 'Noto Sans SC', system-ui, -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif;\n"
    "    --font-mono: ui-monospace, 'SFMono-Regular', 'Cascadia Code', Consolas, monospace;\n"
    "    --font-display: 'Noto Serif SC', 'Songti SC', 'SimSun', Georgia, serif;\n"
    "}\n"
    "\n"
    "/* Light theme overrides（晴空画册浅色） */\n"
    "[data-theme=\"light\"] {\n"
    "    --primary: #E8597A; --primary-dark: #D14A6B; --primary-light: #FBE4EA;\n"
    "    --accent: #5F9E8C; --accent-dark: #4C8374; --accent-light: #E4F0EC;\n"
    "    --neon: #E8A24B; --neon-cyan: #4E88C7;\n"
    "    --bg: #FAF6F3; --bg-card: #FFFFFF; --bg-card-hover: #FBF7F4; --bg-input: #F3EDE8;\n"
    "    --text: #2E2A33; --text-secondary: #6E6673; --text-muted: #9A919C;\n"
    "    --border: #E8DFD9; --border-light: #F1EAE5;\n"
    "    --success: #4E9E73; --warning: #E8A24B; --danger: #D65353; --info: #4E88C7;\n"
    "    --danger-hover: #BC4545; --warning-hover: #E8B35E;\n"
    "    --accent-ink: #1F3A32; --warning-ink: #3A2E1A;\n"
    "    --shadow: 0 1px 3px rgba(46,42,51,.06), 0 8px 28px rgba(46,42,51,.05);\n"
    "    --shadow-sm: 0 1px 3px rgba(46,42,51,.05);\n"
    "}\n"
)
t = t[:start] + tokens_new + t[end:]
print('[OK] :root + light token 替换')

# ── 2. body::before 氛围（去霓虹紫 → 晴空暖调）──
rep(
    "    background: \n"
    "        radial-gradient(ellipse at 20% 50%, rgba(195,137,232,0.10) 0%, transparent 50%),\n"
    "        radial-gradient(ellipse at 80% 20%, rgba(111,227,194,0.08) 0%, transparent 50%),\n"
    "        radial-gradient(ellipse at 50% 80%, rgba(255,95,210,0.05) 0%, transparent 50%);",
    "    background: \n"
    "        radial-gradient(ellipse at 18% 22%, rgba(242,120,154,0.07) 0%, transparent 52%),\n"
    "        radial-gradient(ellipse at 82% 14%, rgba(127,184,166,0.07) 0%, transparent 52%),\n"
    "        radial-gradient(ellipse at 55% 90%, rgba(232,179,94,0.05) 0%, transparent 50%);",
    'body::before 氛围换晴空暖调')

# ── 3. 高频粉紫残影 → color-mix 跟随主题 ──
rep('box-shadow: 0 6px 20px rgba(195,137,232,.35)',
    'box-shadow: 0 6px 20px color-mix(in srgb, var(--primary) 35%, transparent)', '.btn-primary hover 阴影')
rep('box-shadow: 0 6px 20px rgba(111,227,194,.35)',
    'box-shadow: 0 6px 20px color-mix(in srgb, var(--accent) 35%, transparent)', '.btn-accent hover 阴影')
rep('box-shadow: 0 0 0 3px rgba(195,137,232,.30)',
    'box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 30%, transparent)', 'form-control focus 环')
rep('box-shadow: 0 8px 32px rgba(195,137,232,0.12)',
    'box-shadow: var(--shadow-floating)', 'feature-card hover 阴影')
rep('box-shadow:0 4px 14px rgba(195,137,232,.35)',
    'box-shadow:var(--shadow)', 'settings-tab active 柔光')
rep('background:rgba(79,211,168,.14);color:var(--success)',
    'background:color-mix(in srgb,var(--success) 14%,transparent);color:var(--success)', 'health-ok 徽标')
rep('background:rgba(255,122,156,.14);color:var(--danger)',
    'background:color-mix(in srgb,var(--danger) 14%,transparent);color:var(--danger)', 'health-bad 徽标')

# ── 4. 瘦身：合并重复声明 ──
rep('.btn { transition: all var(--transition), transform 0.1s ease; }\n.btn:active { transform: translateY(0) scale(0.97); }\n',
    '', '瘦身：去重复 .btn transition/:active（基础 .btn 已有 transition；:active 在按钮按压区块已定义）')
rep('.card { transition: border-color var(--transition), box-shadow var(--transition), transform var(--transition); }\n.card:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }\n',
    '', '瘦身：去重复 .card transition/hover（已并入基础 .card）')
rep('.card:hover { border-color: var(--border-light); box-shadow: var(--shadow); }',
    '.card:hover { border-color: var(--border-light); box-shadow: var(--shadow); transform: translateY(-1px); }',
    '.card hover 上浮并入基础规则')
rep('th { text-transform: none; letter-spacing: 0.01em; }\nth { background: color-mix(in srgb, var(--bg-card) 55%, transparent); }',
    'th { text-transform: none; letter-spacing: 0.01em; background: color-mix(in srgb, var(--bg-card) 55%, transparent); }',
    '瘦身：合并两个 th 声明')

# ── 5. 追加 M2 视觉增强区块 ──
m2 = (
    nl + nl + '/* 晴空画册 · M2 前台/后台增强（v2.0.0-beta.5） */' + nl
    + '.hero-kicker{display:inline-flex;align-items:center;gap:8px;font-size:.82rem;font-weight:500;color:var(--accent);background:var(--accent-light);padding:6px 14px;border-radius:var(--radius-pill);margin-bottom:16px}' + nl
    + '.hero-banner-wrap{border-radius:var(--radius-lg);overflow:hidden}' + nl
    + '@media(min-width:769px){.hero .stats{border-top:1px solid var(--border);padding-top:20px}.hero .stats .stat{border-left:1px solid var(--border);padding-left:20px}.hero .stats .stat:first-child{border-left:none;padding-left:0}}' + nl
    + '.feature-card{text-align:left;padding:26px 28px}.feature-card .icon{margin:0 0 14px}' + nl
)
t = t + m2

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
size = len(t.encode('utf-8'))
print('[DONE] style.css 写入，字节数 = %d（预算 92160）' % size)
