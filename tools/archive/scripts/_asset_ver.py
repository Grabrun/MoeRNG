import re, glob

# 给所有 /public/css/*.css 和 /public/js/*.js 引用加 ?v=APP_VERSION
views = glob.glob('src/views/**/*.php', recursive=True)
count = 0
for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    # CSS: href="/public/css/xxx.css" → href="/public/css/xxx.css?v=<?= APP_VERSION ?>"
    s = re.sub(
        r'(href="/public/css/[a-z-]+\.css")(?!\?v=)',
        r'\1?v=<?= APP_VERSION ?>',
        s
    )
    # JS: src="/public/js/xxx.js" → src="/public/js/xxx.js?v=<?= APP_VERSION ?>"
    s = re.sub(
        r'(src="/public/js/[a-z-]+\.js")(?!\?v=)',
        r'\1?v=<?= APP_VERSION ?>',
        s
    )
    if s != orig:
        open(v, 'w', encoding='utf-8').write(s)
        n = len(re.findall(r'APP_VERSION', s)) - len(re.findall(r'APP_VERSION', orig))
        print(f'{v}: +{n} 版本号')
        count += 1
print(f'\n{count} 个文件更新')
