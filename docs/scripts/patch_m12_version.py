# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.15 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.14')"
new = "define('APP_VERSION', '2.0.0-beta.15')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.15')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.15] - 2026-09-29\n"
    "\n"
    "### 前台交互文案中文化（front.js 全面审计）\n"
    "\n"
    "- **修复按钮文字不一致**：在线测试按钮 HTML 已为「发送请求」，但 JS 运行时\n"
    "  点击后重置为英文「Send Request」——统一为「发送请求」\n"
    "- 加载态「Loading...」→「加载中…」\n"
    "- 状态徽标：OK→成功、Failed→失败、Network Error→网络错误、Error→错误\n"
    "  （HTTP 标准 statusText 如 404 Not Found 保留不动）\n"
    "- 测试历史记录文案全角化：「302 → 图片（200 成功，X）」；「错误：X」\n"
    "- 随机图预览 alt「Random Image」→「随机图片」；meta 冒号全角化\n"
    "  「分类：X」\n"
    "- 回归：JS 语法（node --check）通过，英文残留复查为 0\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.14] - 2026-09-29"
if '2.0.0-beta.15' in c:
    print('[SKIP] CHANGELOG 已含 beta.15')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.15 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
