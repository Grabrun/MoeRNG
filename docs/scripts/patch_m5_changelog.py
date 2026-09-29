# -*- coding: utf-8 -*-
"""CHANGELOG 顶部加 v1.5 版本谱系说明（未发布开发线的留档交代）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

block = (
    "> **版本谱系说明**：`v1.5.0-beta.1` 是 2026-09-13 开启的开发线（本地媒体根移出 web 根、WebP 转换、\n"
    "> 缩略图字节回填、API 尺寸选择等能力均在此线实装），开线提交 `b91204e` 明确「发布仍等待许可」；\n"
    "> 随后该线被 v2 重构取代（`c888590` 取消全部 v1.5 兼容与迁移）——**v1.5 系列从未正式发版**，\n"
    "> 无 tag / 无 GitHub Release，其能力已并入 v2.0.0-beta 系列。\n"
    "\n"
).replace('\n', nl)

anchor = "## [2.0.0-beta.8] - 2026-09-29"
if '版本谱系说明' in t:
    print('[SKIP] 说明已存在')
elif anchor in t:
    t = t.replace(anchor, block + anchor, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] CHANGELOG v1.5 谱系说明')
else:
    raise SystemExit('[FAIL] anchor')
