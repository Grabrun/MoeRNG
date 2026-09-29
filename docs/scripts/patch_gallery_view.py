# -*- coding: utf-8 -*-
"""v2.0.0-beta.11：gallery 图库显示图片优化

背景：用户观察到 gallery 图库显示图片仅 275x206（= 桌面 4 列卡片渲染尺寸 276x207）。
诉求：图库显示更大、用更合适的缩略图。

改动：
1. CSS .gallery-grid 桌面(>=1024px) 4 列 -> 3 列：
   卡片宽 (1152-32)/3 ≈ 373px（原 276px，图大 35%）
2. gallery.php srcset 档位 sm,md -> sm,md,lg（320/640/1280w）：
   373px 卡片 DPR1 选 md 640（1.7x 超采样清晰）；DPR2 选 lg 1280（1.7x）
3. sizes 同步为 3 列真实列宽（容器内容宽 = min(1152, 100vw-48)，3 列 2 个 16px gap）：
   (max-width:639px) 46vw, (max-width:1023px) 30vw, calc((100vw - 80px) / 3)

gallery 页不分页（全量 sections），改列数不触碰「分页设计不能改」约束。
幂等。
"""
import io, os

ROOT = r"E:\Projects\DouBao\MoeRNG"

def read(p):
    with io.open(os.path.join(ROOT, p), encoding='utf-8') as f:
        return f.read()

def write(p, t):
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8', newline='') as f:
        f.write(t)

# ---- 1) CSS 4 列 -> 3 列（桌面）----
p1 = 'src/public/css/style.css'
t1 = read(p1)
old_css = '@media (min-width: 1024px) { .gallery-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }'
new_css = '@media (min-width: 1024px) { .gallery-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }'
if old_css in t1:
    t1 = t1.replace(old_css, new_css, 1)
    write(p1, t1)
    print('[OK] CSS 桌面 4 列 -> 3 列')
else:
    print('[SKIP] CSS 列数行未命中（可能已改）')

# ---- 2/3) gallery.php srcset + sizes ----
p2 = 'src/views/gallery.php'
t2 = read(p2)
old_srcset = "$ss = $img->srcset('sm,md');"
new_srcset = "$ss = $img->srcset('sm,md,lg');"
old_sizes = "'(max-width: 639px) 46vw, (max-width: 1023px) 30vw, 240px'"
new_sizes = "'(max-width: 639px) 46vw, (max-width: 1023px) 30vw, calc((100vw - 80px) / 3)'"
ok = 0
if old_srcset in t2:
    t2 = t2.replace(old_srcset, new_srcset, 1); ok += 1
if old_sizes in t2:
    t2 = t2.replace(old_sizes, new_sizes, 1); ok += 1
if ok == 2:
    write(p2, t2)
    print('[OK] gallery.php srcset sm,md,lg + sizes 3 列真实列宽')
else:
    print('[SKIP] gallery.php 命中 %d/2' % ok)

print('[DONE]')
