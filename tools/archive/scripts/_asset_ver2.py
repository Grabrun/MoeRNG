import re, glob

views = glob.glob('src/views/**/*.php', recursive=True)
for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    # 统一规范：href="/public/css/x.css?v=<?= APP_VERSION ?>"
    s = re.sub(r'href="/public/css/([a-z-]+\.css)"[^>]*',
               r'href="/public/css/\1?v=<?= APP_VERSION ?>"', s)
    s = re.sub(r'src="/public/js/([a-z-]+\.js)"[^>]*',
               r'src="/public/js/\1?v=<?= APP_VERSION ?>"', s)
    if s != orig:
        open(v, 'w', encoding='utf-8').write(s)
        print(f'fixed {v}')
