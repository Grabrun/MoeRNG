import re, glob

views = glob.glob('src/views/**/*.php', recursive=True)
for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    # 修正：引号外版本号 → 引号内
    s = s.replace('"href="/public/css/', 'href="/public/css/').replace('?v=<?= APP_VERSION ?>>', '?v=<?= APP_VERSION ?>">')
    # 更精确的修复
    s = re.sub(r'(href="/public/css/[a-z-]+\.css)"\?v=<\?= APP_VERSION \?>',
               r'\1?v=<?= APP_VERSION ?>"', s)
    s = re.sub(r'(src="/public/js/[a-z-]+\.js)"\?v=<\?= APP_VERSION \?>',
               r'\1?v=<?= APP_VERSION ?>"', s)
    if s != orig:
        open(v, 'w', encoding='utf-8').write(s)
        print(f'fixed {v}')
