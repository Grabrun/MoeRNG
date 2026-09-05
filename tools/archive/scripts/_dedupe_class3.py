import re, glob

# 第三形态：同一标签内两个真正的 class="..." 属性（中间隔其它属性）→ 合并为单个。
# 关键：用 (?<![-\w]) 负向后顾排除 data-class / data-toggle-class 等含 class 的 data-*
# 属性名，只匹配独立的 class="..." 属性（class 前面必须是空白或标签起始，不能是 - 或字母）。

CLASS_ATTR = r'(?<![-\w])class="([^"]*)"'   # 排除 data-class= 等

def merge_tag(tag):
    matches = list(re.finditer(CLASS_ATTR, tag))
    if len(matches) < 2:
        return tag
    first = matches[0]
    classes = []
    for m in matches:
        classes.extend(m.group(1).split())
    seen = []
    for c in classes:
        if c and c not in seen:
            seen.append(c)
    merged = ' '.join(seen)
    # collapse 只删真正的 class="..."（同样的负向后顾），保留 data-class 等
    def collapse(s):
        return re.sub(r'\s*' + CLASS_ATTR, '', s)
    head = collapse(tag[:first.start()]) + 'class="' + merged + '"'
    tail = collapse(tag[first.end():])
    return head + tail

count = [0]
def repl(m):
    t2 = merge_tag(m.group(0))
    if t2 != m.group(0):
        count[0] += 1
    return t2

views = glob.glob('src/views/**/*.php', recursive=True)
for v in views:
    s = open(v, encoding='utf-8').read()
    s2 = re.sub(r'<[^>]+>', repl, s)
    if s2 != s:
        open(v, 'w', encoding='utf-8').write(s2)
        # 统计剩余"真重复 class"（负向后顾）
        rem = len(re.findall(CLASS_ATTR + r'[^>]*' + CLASS_ATTR, s2))
        print(f'{v}: 剩余真重复class {rem}')
print(f'\n共处理 {count[0]} 处跨属性重复 class')
