# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.13 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.12')"
new = "define('APP_VERSION', '2.0.0-beta.13')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.13')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.13] - 2026-09-29\n"
    "\n"
    "### 图片处理页按钮合并：「重试失败项」\n"
    "\n"
    "- 合并原「重试全部失败」+「重试元数据补全」两个按钮为单一「重试失败项」\n"
    "- 一键依次完成三步：① 主图失败项（process_status='failed'）重新入队；\n"
    "  ② 处理队列（含刚入队的失败项，进度条 + 统计联动）；\n"
    "  ③ 缩略图元数据失败项（partial/failed）定向重试\n"
    "- 快路径：两者皆空时提示「没有需要重试的失败项」，不再空跑请求\n"
    "- 按钮禁用逻辑合并：主图失败与元数据失败皆无时才置灰\n"
    "- 删除 queue-retry-meta 按钮（HTML + JS 事件），runThumbMetaRetry 函数保留复用；\n"
    "  提示条措辞同步更新\n"
    "- 回归：JS 语法（node --check）、PHP syntax 全绿\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.12] - 2026-09-29"
if '2.0.0-beta.13' in c:
    print('[SKIP] CHANGELOG 已含 beta.13')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.13 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
