import re, glob

# 合并重复 class 属性：class="X" class="Y" → class="X Y"（去重，保留首次顺序）
# 循环处理：可能出现 class="X" class="Y" class="Z" 三连
def merge_dupes(s):
    prev = None
    while prev != s:
        prev = s
        s = re.sub(
            r'class="([^"]*)" class="([^"]*)"',
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
        # 统计合并处数
        before = len(re.findall(r'class="[^"]*" class="', s))
        after = len(re.findall(r'class="[^"]*" class="', s2))
        open(v, 'w', encoding='utf-8').write(s2)
        total += (before - after)
        print(f'{v}: {before-after} 处合并（剩 {after}）')
print(f'\n共合并 {total} 处重复 class 属性')
