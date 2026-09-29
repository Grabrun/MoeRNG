# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.12 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.11')"
new = "define('APP_VERSION', '2.0.0-beta.12')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.12')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.12] - 2026-09-29\n"
    "\n"
    "### 处理队列触发策略重构（按用户诉求）\n"
    "\n"
    "- **移除**「页面加载 1.5s 后自动消费处理队列」—— 后台访问图片页不再随时触发处理\n"
    "- **保留两个触发时机**（用户指定）：① 上传批次完成后自动跑一次（一次清空全部\n"
    "  pending）；② 用户手动触发（图片处理页「处理」按钮 / 图片管理页「重试失败项」）\n"
    "- **并发防护**：processQueue 标记 processing 改为条件更新（AND process_status='pending'），\n"
    "  并发请求（上传完成 + 手动同点）不再重复拾取同一批；被抢先时整批放弃、\n"
    "  下次轮询自然拾取剩余\n"
    "- **batch 3 → 10**：前端 BATCH 与服务端默认值（processQueue / backfillThumbs）\n"
    "  同步放大，积压处理轮数减少约 2/3\n"
    "- 历史积压不依赖页面访问：`php src/cli/backfill-thumbs.php` 一次补齐\n"
    "- 回归：PHP syntax 全绿、php_undef 全绿、JS 语法（node --check）通过\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.11] - 2026-09-29"
if '2.0.0-beta.12' in c:
    print('[SKIP] CHANGELOG 已含 beta.12')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.12 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
