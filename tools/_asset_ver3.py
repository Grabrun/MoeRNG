import glob

# 安全版本号添加：纯字符串替换，逐项验证
# 目标格式：href="/public/css/style.css?v=<?= APP_VERSION ?>"（PHP 闭合 + 引号 + 原 > 保留）
REPLACEMENTS = [
    ('href="/public/css/style.css">', 'href="/public/css/style.css?v=<?= APP_VERSION ?>">'),
    ('href="/public/css/style.css"', 'href="/public/css/style.css?v=<?= APP_VERSION ?>"'),
    ('href="/public/css/fonts.css">', 'href="/public/css/fonts.css?v=<?= APP_VERSION ?>">'),
    ('href="/public/css/fonts.css"', 'href="/public/css/fonts.css?v=<?= APP_VERSION ?>"'),
    ('src="/public/js/helpers.js">', 'src="/public/js/helpers.js?v=<?= APP_VERSION ?>">'),
    ('src="/public/js/helpers.js"', 'src="/public/js/helpers.js?v=<?= APP_VERSION ?>"'),
    ('src="/public/js/app.js">', 'src="/public/js/app.js?v=<?= APP_VERSION ?>">'),
    ('src="/public/js/app.js"', 'src="/public/js/app.js?v=<?= APP_VERSION ?>"'),
]

views = glob.glob('src/views/**/*.php', recursive=True)
for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    for a, b in REPLACEMENTS:
        s = s.replace(a, b)
    if s != orig:
        # 验证：无残留原始引用 + 无引号破坏
        bad = False
        for a, _ in REPLACEMENTS:
            if a in s:
                bad = True
        open(v, 'w', encoding='utf-8').write(s)
        print(f'{"OK " if not bad else "BAD "}{v}')
