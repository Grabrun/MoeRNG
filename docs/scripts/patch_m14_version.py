# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.17 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.16')"
new = "define('APP_VERSION', '2.0.0-beta.17')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.17')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.17] - 2026-10-03\n"
    "\n"
    "### 修复：删除图片时对象存储残留缩略图（软删除疑云根因）\n"
    "\n"
    "- 此前 `Image::delete()` 只删除主文件 `path`，同一资产前缀下的三档缩略图\n"
    "  （`thumb-sm/md/lg.webp`）残留在对象存储——删图后存储不释放，看起来像\n"
    "  「软删除」。数据库侧始终是硬删除（DELETE 行），无软删除逻辑。\n"
    "- 修复：删除时按 `thumbs` JSON（权威登记，`thumb_path` 列 = md 冗余）\n"
    "  逐 key 删除全部缩略图，主文件与缩略图去空去重后一次性处理。\n"
    "- 存储删除失败不再静默吞掉：DB 删除成功后若存储删除有失败项，写入审计\n"
    "  `image_delete_storage_failed`（含 id 与失败路径清单），便于排查孤儿文件。\n"
    "- 说明：历史已删除图片留下的孤儿缩略图无法自动清扫（`StorageInterface` 无\n"
    "  LIST 能力），需到对象存储控制台按 `{yyyy}/{mm}/{uuid}/` 前缀人工清理。\n"
    "- 验证：PHP syntax 全绿；删除覆盖逻辑三用例断言通过（3 档全删 / 仅 md\n"
    "  去重 / 无缩略图仅主文件）。\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.16] - 2026-10-03"
if '2.0.0-beta.17' in c:
    print('[SKIP] CHANGELOG 已含 beta.17')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.17 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
