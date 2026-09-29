# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.14 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.13')"
new = "define('APP_VERSION', '2.0.0-beta.14')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.14')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.14] - 2026-09-29\n"
    "\n"
    "### 前台文案优化（中文优先 + 一致性 + 渲染修复）\n"
    "\n"
    "- 修复 docs 页渲染 bug：`**功能性要求**` markdown 星号残留 → `<strong>`\n"
    "- 在线测试页中文化：按钮「Send Request」→「发送请求」；\n"
    "  「Redirect (图片直出)」→「重定向（直接输出图片）」；结果提示同步\n"
    "- 页脚中文化：「Open-source under MIT License」→「开源项目（MIT License）」\n"
    "- 首页统计说明精简：「实时统计 · 图片与分类随后台更新」\n"
    "- 关于页 GitHub 未配置提示改为面向访客的明确表述\n"
    "- 回归：PHP syntax 全绿\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.13] - 2026-09-29"
if '2.0.0-beta.14' in c:
    print('[SKIP] CHANGELOG 已含 beta.14')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.14 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
