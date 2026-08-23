import re, glob

# 增强版：跨行重复 class 合并（class="X" \n class="Y" → class="X Y"）
# 上一版正则只匹配同行（" class=" 之间恰好一个空格），漏掉换行场景
def merge_dupes(s):
    prev = None
    while prev != s:
        prev = s
        s = re.sub(
            r'class="([^"]*)"\s*class="([^"]*)"',
            lambda m: 'class="' + ' '.join(dict.fromkeys(
                (m.group(1) + ' ' + m.group(2)).split()
            )) + '"',
            s
        )
    return s

views = glob.glob('src/views/**/*.php', recursive=True)
total = 0
for v in views:
    s = open(v, encoding='utf-8').read()
    orig = s
    s2 = merge_dupes(s)
    if s2 != s:
        before = len(re.findall(r'class="[^"]*"\s*class="', s))
        after = len(re.findall(r'class="[^"]*"\s*class="', s2))
        open(v, 'w', encoding='utf-8').write(s2)
        total += (before - after)
        print(f'{v}: {before-after} 处合并（剩 {after}）')
print(f'\n共合并 {total} 处跨行重复 class')
