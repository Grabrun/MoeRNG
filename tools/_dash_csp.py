p = 'src/views/admin/dashboard.php'
s = open(p, encoding='utf-8').read()
orig = s
repl = [
    # 统计图标颜色
    ('style="color:var(--primary)"', 'class="c-primary"'),
    ('style="color:var(--accent)"', 'class="c-accent"'),
    ('style="color:var(--info)"', 'class="c-info"'),
    ('style="color:var(--warning)"', 'class="c-warning"'),
    # 最近上传网格 + 瓦片 + 图片（静态 → 类）
    ('style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px"', 'class="recent-grid"'),
    ('style="position:relative;display:block;min-width:0;border-radius:var(--radius-sm);overflow:hidden;aspect-ratio:1;background:var(--bg-input)"', 'class="tile"'),
    ('style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"', ''),
    # 分类分布
    ('style="display:flex;flex-direction:column;gap:8px"', 'class="vstack"'),
    ('style="display:flex;align-items:center;gap:10px"', 'class="cat-row"'),
    ('style="width:120px;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"', 'class="cat-name"'),
    ('style="flex:1;height:8px;border-radius:999px;background:var(--bg-input);overflow:hidden"', 'class="cat-bar"'),
    ('style="width:40px;text-align:right;font-size:.85rem;color:var(--text-secondary)"', 'class="cat-count"'),
    # 趋势图（上传）
    ('style="display:flex;align-items:flex-end;gap:6px;height:90px;padding-top:8px"', 'class="trend-chart"'),
    ('style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px"', 'class="trend-col"'),
    ('style="font-size:.7rem;color:var(--text-secondary)"', 'class="trend-num"'),
    ('style="font-size:.65rem;color:var(--text-muted)"', 'class="trend-day"'),
    # 存储用量
    ('style="font-size:1.4rem;font-weight:600;color:var(--text)"', 'class="usage-num"'),
    # 最近操作
    ('style="display:flex;flex-direction:column;gap:0"', 'class="vstack" style="gap:0"'),
    ('style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:.88rem"', 'class="log-row"'),
    ('style="background:var(--bg-input);color:var(--text-secondary);padding:2px 8px;border-radius:6px;white-space:nowrap"', 'class="log-action"'),
    ('style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"', 'class="log-detail"'),
    ('style="color:var(--text-muted);font-size:.78rem;white-space:nowrap"', 'class="log-time"'),
    # 流量统计
    ('style="justify-content:space-between;align-items:baseline"', 'class="flex flow-summary-head"'),
    ('style="font-size:.82rem;color:var(--text-muted)"', 'class="text-small"'),
    ('style="color:var(--primary)"', 'class="c-primary"'),
    ('style="text-align:center;margin:8px 0 16px;padding:12px 0;border:1px solid var(--border);border-radius:var(--radius)"', 'class="traffic-summary"'),
    ('style="font-size:1.3rem;font-weight:600;color:var(--primary)"', 'class="traffic-num primary"'),
    ('style="font-size:1.3rem;font-weight:600;color:var(--accent)"', 'class="traffic-num accent"'),
    ('style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:.88rem"', 'class="flow-grid"'),
    ('style="padding:10px 12px;border-radius:var(--radius);background:var(--bg-input)"', 'class="flow-box"'),
    ('style="justify-content:space-between"', 'class="flex"'),
    ('style="font-size:.78rem"', 'class="flow-note"'),
    ('style="font-size:.95rem"', 'class="text-small"'),
    ('style="display:flex;align-items:flex-end;gap:6px;height:96px;padding-top:8px"', 'class="trend-chart-96"'),
    ('style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px"', 'class="flow-col"'),
    ('style="display:flex;gap:2px;align-items:flex-end;height:60px"', 'class="flow-bars"'),
    ('style="gap:16px;margin-top:8px;font-size:.78rem;color:var(--text-secondary)"', 'class="legend"'),
    ('style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--primary);vertical-align:middle;margin-right:4px"', 'class="legend-dot primary"'),
    ('style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--accent);vertical-align:middle;margin-right:4px"', 'class="legend-dot accent"'),
    # 运行状态
    ('style="display:flex;flex-direction:column;gap:14px;margin-bottom:20px"', 'class="metric-stack"'),
    ('style="justify-content:space-between;margin-bottom:4px"', 'class="metric-head"'),
    ('style="font-size:.88rem"', 'class="metric-label"'),
    ('style="font-size:.88rem;color:var(--text-secondary)"', 'class="metric-value"'),
    ('style="height:10px;border-radius:999px;background:var(--bg-input);overflow:hidden"', 'class="metric-track"'),
    ('style="margin-top:0;font-size:.78rem;margin-bottom:14px"', 'class="foot-note"'),
    ('style="text-align:center;margin-bottom:16px"', 'class="text-center" style="margin-bottom:16px"'),
    ('style="font-size:.85rem"', 'class="text-small"'),
    # 动态高度 → data 属性（JS 补设）
    ('style="width:100%;max-width:34px;height:<?= max(4, round($t[\'count\'] / $maxTrend * 60)) ?>px;border-radius:6px 6px 0 0;background:linear-gradient(180deg,var(--primary),var(--accent))"',
     'class="trend-bar" data-h="<?= max(4, round($t[\'count\'] / $maxTrend * 60)) ?>"'),
    ('style="width:<?= round($cat[\'count\'] / $maxCount * 100) ?>%;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--primary),var(--accent))"',
     'class="cat-bar-fill" data-w="<?= round($cat[\'count\'] / $maxCount * 100) ?>"'),
]
for a, b in repl:
    if a in s:
        s = s.replace(a, b, 1)
    else:
        print('MISS:', a[:60])

open(p, 'w', encoding='utf-8').write(s)
print('剩余 inline style:', s.count('style="'))
