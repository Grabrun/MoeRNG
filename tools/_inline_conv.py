import re, glob

# 转换所有视图的 inline handler 为 data-* 委托属性
views = glob.glob('src/views/**/*.php', recursive=True)
changed = 0

for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s

    # 1. closeModal('xxx') → data-close-modal="xxx"
    s = re.sub(r"onclick=\"closeModal\('([^']+)'\)\"", r'data-close-modal="\1"', s)
    # 2. openModal('xxx') → data-open-modal="xxx"
    s = re.sub(r"onclick=\"openModal\('([^']+)'\)\"", r'data-open-modal="\1"', s)
    # 3. images upload modal toggle (inline classList)
    s = re.sub(r"onclick=\"document\.getElementById\('([^']+)'\)\.classList\.(add|remove)\('([^']+)'\)\"",
               r'data-toggle-class="\1" data-class="\3"', s)
    # 4. captcha refresh → data-refresh-captcha
    s = s.replace('onclick="this.src=\'/admin/captcha?\'+Date.now()"', 'data-refresh-captcha')
    # 5. logs per_page → data-auto-submit
    s = s.replace('onchange="this.form.submit()"', 'data-auto-submit')
    # 6. install storage driver → data-storage-driver-toggle
    s = s.replace('onchange="toggleStorageFields()"', 'data-storage-driver-toggle')

    if s != orig:
        open(v, 'w', encoding='utf-8').write(s)
        n = orig.count('onclick=') - s.count('onclick=')
        print(f'{v}: {n} handlers converted')
        changed += 1

print(f'\n{changed} files updated')
