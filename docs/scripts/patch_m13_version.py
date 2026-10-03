# -*- coding: utf-8 -*-
"""bump v2.0.0-beta.16 + CHANGELOG 条目（幂等）"""
import io

ROOT = r"E:\Projects\DouBao\MoeRNG"

p = ROOT + r"\src\bootstrap.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()
old = "define('APP_VERSION', '2.0.0-beta.15')"
new = "define('APP_VERSION', '2.0.0-beta.16')"
if old in t:
    t = t.replace(old, new, 1)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] APP_VERSION → 2.0.0-beta.16')
else:
    print('[SKIP] 版本行未命中（可能已 bump）')

p2 = ROOT + r"\CHANGELOG.md"
with io.open(p2, encoding='utf-8') as f:
    c = f.read()
nl = '\r\n' if '\r\n' in c else '\n'
entry = (
    "## [2.0.0-beta.16] - 2026-10-03\n"
    "\n"
    "### 前台各界面文案整体重写\n"
    "\n"
    "- 首页：hero 副标题改为价值导向（调用一次/拿来即用/完全自控）；\n"
    "  特性卡全部重写为顺畅叙述（无缓存无规律、子分类一起参与、低于 50ms 等）；\n"
    "  抽图占位改为「点『试试手气』，随机取一张看看效果」\n"
    "- 图库：引导语与空态重写（刷新页面换一批 / 去后台上传第一批）\n"
    "- 关于页：简介改为定位式陈述（图片托管与随机接口一站搞定）；\n"
    "  技术特性逐条打磨；开源寄语补充\n"
    "- API 文档：统一全角标点与空格（分类标识（slug）、302 重定向、嵌套 JSON、\n"
    "  sm（320）等），技术语义零改动\n"
    "- 回归：PHP syntax 全绿\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.15] - 2026-09-29"
if '2.0.0-beta.16' in c:
    print('[SKIP] CHANGELOG 已含 beta.16')
elif anchor in c:
    c = c.replace(anchor, entry + anchor, 1)
    with io.open(p2, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print('[OK] CHANGELOG beta.16 条目')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor 未命中')
