# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.10 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.9')"
new = "define('APP_VERSION', '2.0.0-beta.10')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.10')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.10] - 2026-09-29\n"
    "\n"
    "### 修复：首页白屏（hotfix）\n"
    "\n"
    "- **根因**：beta.9 在视图（front_header.php / admin/layout.php）中引入 `<script<?= CspNonce::attr() ?>>`，\n"
    "  未限定名调用依赖「视图在 App\\Core 命名空间上下文被 Controller::render() include」的隐式约定；\n"
    "  若任一渲染路径不在该上下文（install 旁路等），解析为 \\CspNonce 触发 PHP Fatal Error（Class not found）\n"
    "  → 输出缓冲（ob_start）被丢弃 → 整页白屏，banner 未渲染（preload not used 伴随警告）\n"
    "- **修复**：视图内改用全限定名 `\\App\\Core\\CspNonce::attr()`（不依赖命名空间继承，任何上下文均正确解析）；\n"
    "  字体切换脚本 try/catch 静默降级（异常不中断页面）\n"
    "- **伴随**：CSP `connect-src` 放行字体两跳域（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com），\n"
    "  消除 preconnect 被 `connect-src 'self'` 拦截的控制台报错\n"
    "- 回归：全量 harness 全绿（syntax/design 63/api 67/hero 36/perf 74/layout 125/矩阵/scope/click/front 运行时）\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.9] - 2026-09-29"
if '2.0.0-beta.10' in c:
    print('[SKIP] CHANGELOG 已含 beta.10')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.10 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
