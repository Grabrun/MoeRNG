import re, glob

views = glob.glob('src/views/**/*.php', recursive=True)

for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    # onclick="return confirm('...')" → data-confirm="..."
    s = re.sub(r"onclick=\"return confirm\('([^']*)'\)\"", r'data-confirm="\1"', s)
    # onsubmit="return confirm('...')" → data-confirm-submit="..."
    s = re.sub(r"onsubmit=\"return confirm\('([^']*)'\)\"", r'data-confirm-submit="\1"', s)
    if s != orig:
        open(v, 'w', encoding='utf-8').write(s)
        print(f'{v}: converted')
