# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.11 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.10')"
new = "define('APP_VERSION', '2.0.0-beta.11')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.11')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.11] - 2026-09-29\n"
    "\n"
    "### 新增：前台图库缩略图一键补全（CLI 工具）\n"
    "\n"
    "- **背景**：前台 /gallery 对「缩略图缺失」的图片回退加载原图 —— 275×206 的显示空间\n"
    "  加载数 MB 原图，带宽浪费严重（用户反馈「使用了原图而不是合适的缩略图」）\n"
    "- **根因**：缩略图为异步队列生成（processing_state.thumb_meta 停在 pending）；\n"
    "  历史图（v2 迁移前）与从未触发队列的图没有缩略图 → displayUrl('sm') 回退链\n"
    "  （sm → md → 原图）到底 → 前台 /gallery 加载原图。前台代码本身已正确使用缩略图\n"
    "  （displayUrl('sm') + srcset sm,md + sizes 240px），无需改动\n"
    "- **交付**：`src/cli/backfill-thumbs.php`（父循环脚本）+ `src/cli/backfill-thumbs-batch.php`\n"
    "  （单批子进程）—— 构造带合法 CSRF 的 Request，走 ImageController::backfillThumbs()\n"
    "  同一份生成/上传/落库逻辑（零分叉），部署后执行一次即补齐全部历史缩略图，\n"
    "  前台 /gallery 自动改用 sm 320w 缩略图（1.16x 超采样，带宽 -90%+）\n"
    "- **用法**：`php src/cli/backfill-thumbs.php [--batch 10] [--retry]`；\n"
    "  亦可使用后台「图片管理 → 补全历史缩略图」按钮（同一逻辑）\n"
    "- 回归：syntax 全绿、php_undef 全绿\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.10] - 2026-09-29"
if '2.0.0-beta.11' in c:
    print('[SKIP] CHANGELOG 已含 beta.11')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.11 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
