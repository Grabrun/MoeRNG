# -*- coding: utf-8 -*-
"""v2.0.0-beta.4: 版本 bump + CHANGELOG + 归档"""
import io, subprocess, os, sys

ROOT = r"E:\Projects\DouBao\MoeRNG"

def patch(path, old, new, tag):
    with io.open(path, encoding='utf-8', newline='') as f:
        t = f.read()
    nl = '\r\n' if '\r\n' in t else '\n'
    old = old.replace('\n', nl)
    new = new.replace('\n', nl)
    if old not in t:
        raise SystemExit('[FAIL] %s anchor not found: %s' % (tag, path))
    t = t.replace(old, new, 1)
    with io.open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print('[OK] %s' % tag)

# bootstrap.php
patch(os.path.join(ROOT, 'src', 'bootstrap.php'),
    "// v2.0.0-beta.3: 图片/读取架构审计 —— finfo 提循环、大图流式下载、基线余量常量化、删死函数 e()。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.3');\n"
    "}",
    "// v2.0.0-beta.3: 图片/读取架构审计 —— finfo 提循环、大图流式下载、基线余量常量化、删死函数 e()。\n"
    "// v2.0.0-beta.4: 后台设置排版与 UI 优化 —— 标签行 pill 化、分组卡标题强调条、设置项 hover、健康结果状态徽标、保存提示警告色。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.4');\n"
    "}",
    'bootstrap.php: 2.0.0-beta.4')

# CHANGELOG.md
ch = os.path.join(ROOT, 'CHANGELOG.md')
with io.open(ch, encoding='utf-8', newline='') as f:
    ct = f.read()
cnl = '\r\n' if '\r\n' in ct else '\n'
anchor = ("## [2.0.0-beta.3] - 2026-09-28").replace('\n', cnl)
entry = (
    "## [2.0.0-beta.4] - 2026-09-28\n"
    "\n"
    "> 后台「系统设置」排版与 UI 优化（全 CSS 增强，结构/交互挂钩零变动）。\n"
    "\n"
    "### 🎨 视觉增强\n"
    "\n"
    "- **设置标签行 pill 化**：active 标签渐变底 + 柔光，非 active 悬停高亮\n"
    "- **分组卡片标题左缘强调条**：渐变竖条区分分组层级，描述区留白对齐\n"
    "- **设置项 hover 高亮**：浅紫底 + 圆角，label 加粗；输入控件聚焦环已有，整体层级更清晰\n"
    "- **批处理面板标题状态点**：统一视觉锚点\n"
    "- **健康检查结果状态徽标**：`[ OK ]` 绿 pill / `[待修复]` 红 pill（renderHealth 输出 health-ok/health-bad 类）\n"
    "- **保存栏**：未保存提示改为警告色；工具栏窄屏自动换行\n"
    "\n"
    "### ✅ 约束保持\n"
    "\n"
    "- design_contract 63 项全过：分组头/工具栏/3×batch-panel/22 个 JS 挂钩 id/关键文案零变动；CSS 92141B < 90KB 预算；CSP 无 inline style\n"
    "- scope/click/page_matrix/syntax 全绿；桌面+移动截图自检正常\n"
).replace('\n', cnl)
if anchor not in ct:
    raise SystemExit('[FAIL] CHANGELOG anchor not found')
ct = ct.replace(anchor, entry + anchor, 1)
with io.open(ch, 'w', encoding='utf-8', newline='') as f:
    f.write(ct)
print('[OK] CHANGELOG: v2.0.0-beta.4')
