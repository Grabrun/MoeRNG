p = 'src/views/home.php'
s = open(p, encoding='utf-8').read()
repl = [
    ('style="display:none" class="rd-image-preview"', 'class="rd-image-preview" style="display:none"'),
    ('style="font-size:.82rem;color:var(--text-secondary);margin-top:8px"', 'class="rd-meta"'),
    ('style="width:150px;flex-shrink:0"', 'class="rd-cat-select"'),
    ('style="display:none"', 'class="hidden"'),
    ('class="text-muted" style="margin-left:auto"', 'class="text-muted doc-endpoint-label"'),
    ('class="form-group" style="flex:1;min-width:180px;margin-bottom:0"', 'class="form-group tester-field"'),
    ('<div style="display:flex;align-items:flex-end">', '<div class="tester-actions">'),
    ('style="word-break:break-all"', 'class="wrap-all"'),
    ('style="display:none;margin-top:10px;font-size:.8rem;color:var(--text-secondary)"', 'class="hidden test-meta"'),
    ('style="margin-left:8px"', 'class="test-duration"'),
    ('style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px"', 'class="about-grid"'),
    ('style="background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:20px"', 'class="about-card"'),
    ('style="font-size:0.92rem;line-height:1.7"', 'class="about-text"'),
    ('style="font-size:0.92rem;line-height:1.9;padding-left:18px;margin:0"', 'class="about-list"'),
    ('style="font-size:0.92rem;line-height:1.7;margin-bottom:12px"', 'class="about-text about-text-mb"'),
    ('style="font-size:0.85rem"', 'class="text-small"'),
    ('style="color:inherit;text-decoration:none"', 'class="footer-link"'),
    ('style="white-space:pre-line"', 'class="footer-custom"'),
]
for a, b in repl:
    n = s.count(a)
    if n:
        s = s.replace(a, b)
    else:
        print('MISS:', a[:50])
open(p, 'w', encoding='utf-8').write(s)
print('剩余 inline style:', s.count('style="'))
