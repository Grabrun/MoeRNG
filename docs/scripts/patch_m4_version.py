# -*- coding: utf-8 -*-
"""字体 CSP 修复：bump 2.0.0-beta.7 → 2.0.0-beta.8 + CHANGELOG"""
import io

def load(p):
    with io.open(p, encoding='utf-8', newline='') as f:
        return f.read()

def save(p, t):
    nl = '\r\n' if '\r\n' in t else '\n'
    t = t.replace('\r\n', '\n').replace('\n', nl)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)

# 1. bootstrap.php
p = r"E:\Projects\DouBao\MoeRNG\src\bootstrap.php"
t = load(p)
nl = '\r\n' if '\r\n' in t else '\n'
old = (
    "// v2.0.0-beta.7: 覆盖安装重置修复 —— src/config/*.php 整体移出版本控制（installed 标志/凭据均为安装器生成的本地状态），build_release 归档排除 config/*.php，覆盖部署不再把 installed 重置回安装向导。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.7');\n"
    "}"
).replace('\n', nl)
new = (
    "// v2.0.0-beta.7: 覆盖安装重置修复 —— src/config/*.php 整体移出版本控制（installed 标志/凭据均为安装器生成的本地状态），build_release 归档排除 config/*.php，覆盖部署不再把 installed 重置回安装向导。\n"
    "// v2.0.0-beta.8: 字体 CSP 修复 —— miaoda.feishu.cn 字体 CSS 入口的 @font-face 实际指向 sf3-scmcdn-cn.feishucdn.com 分片（两跳分离），font-src 放行字体文件域名，思源字体恢复正常加载。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.8');\n"
    "}"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] version anchor')
save(p, t.replace(old, new, 1))
print('[OK] bootstrap.php: 2.0.0-beta.8')

# 2. CHANGELOG
p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
t = load(p)
nl = '\r\n' if '\r\n' in t else '\n'
block = (
    "## [2.0.0-beta.8] - 2026-09-29\n"
    "\n"
    "> 字体 CSP 修复：思源宋体/黑体恢复正常加载（此前被内容安全策略批量拦截，页面回退系统字体）。\n"
    "\n"
    "### 🐛 修复\n"
    "\n"
    "- **字体加载被 CSP 拦截**：M2 引入的字体镜像 `miaoda.feishu.cn` 的 `css2` 入口返回的 `@font-face` 实际指向 `sf3-scmcdn-cn.feishucdn.com` 的 woff2 分片（入口与字体文件两跳分离）；CSP `font-src` 此前仅放行入口域名，导致全部 505 个字体分片被浏览器阻止（控制台批量报错 `violates font-src 'self' https://miaoda.feishu.cn`，页面回退系统字体）\n"
    "- **修复**：`font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com` —— 放行实测唯一的字体文件域名（精确最小化，未使用宽泛子域通配）\n"
    "\n"
    "### ✅ 回归\n"
    "\n"
    "- syntax 2912/2912；API 契约 67 项、design_contract 63 项、page_matrix / hero_stats 全绿\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.7] - 2026-09-28"
if '## [2.0.0-beta.8]' in t:
    print('[SKIP] beta.8 已存在')
elif anchor in t:
    save(p, t.replace(anchor, block + anchor, 1))
    print('[OK] CHANGELOG beta.8')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor')
