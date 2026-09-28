# -*- coding: utf-8 -*-
"""覆盖安装重置修复：bump 2.0.0-beta.6 → 2.0.0-beta.7 + CHANGELOG"""
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
    "// v2.0.0-beta.6: 空库 404 优雅化 —— /api/v1/random 空库/分类空返回 JSON 错误体（中文引导 + error 保持英文兼容）；tester 前端非 2xx 展示服务端 message / 网关提示；文档补充空库行为。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.6');\n"
    "}"
).replace('\n', nl)
new = (
    "// v2.0.0-beta.6: 空库 404 优雅化 —— /api/v1/random 空库/分类空返回 JSON 错误体（中文引导 + error 保持英文兼容）；tester 前端非 2xx 展示服务端 message / 网关提示；文档补充空库行为。\n"
    "// v2.0.0-beta.7: 覆盖安装重置修复 —— src/config/*.php 整体移出版本控制（installed 标志/凭据均为安装器生成的本地状态），build_release 归档排除 config/*.php，覆盖部署不再把 installed 重置回安装向导。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.7');\n"
    "}"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] version anchor')
save(p, t.replace(old, new, 1))
print('[OK] bootstrap.php: 2.0.0-beta.7')

# 2. CHANGELOG
p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
t = load(p)
nl = '\r\n' if '\r\n' in t else '\n'
block = (
    "## [2.0.0-beta.7] - 2026-09-28\n"
    "\n"
    "> 覆盖安装重置修复：发布包与仓库不再携带 `config/app.php`（`installed=false`），覆盖部署不再把已安装站点重置回安装向导。\n"
    "\n"
    "### 🐛 修复\n"
    "\n"
    "- **覆盖安装重置根因**：`src/config/app.php` 被 git 跟踪且被打进 release 归档——该文件由安装器写入（安装完成 `installed=true`），仓库/归档里的 `installed=false` 版本在覆盖部署时把安装状态覆盖重置，导致每次覆盖后重新显示安装界面\n"
    "- **修复措施**：① `src/config/*.php` 整体移出版本控制（`git rm --cached` + `.gitignore`；database.php / signing_key.php 两条旧规则同步清理尾随注释——本项目 git 会把行中 `#` 及之后当作模式内容，带注释的规则静默失效）；② `build_release.py` 归档排除 `config/*.php`，仅保留 `config/.htaccess`（防目录列出的安全文件）\n"
    "- 全新部署仍正常：归档无 config/ 时 `Config::load()` 自愈生成默认 `app.php`（`installed=false`）供安装向导引导，安装完成写盘 `installed=true`；此后任何覆盖（解压 release / git pull）都不会再触碰该文件\n"
    "\n"
    "### ✅ 回归\n"
    "\n"
    "- syntax 2912/2912；API 契约 67 项、design_contract 63 项、page_matrix 全绿；归档校验：`config/` 仅含 `.htaccess`，无 `*.php`\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.6] - 2026-09-28"
if '## [2.0.0-beta.7]' in t:
    print('[SKIP] beta.7 已存在')
elif anchor in t:
    save(p, t.replace(anchor, block + anchor, 1))
    print('[OK] CHANGELOG beta.7')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor')
