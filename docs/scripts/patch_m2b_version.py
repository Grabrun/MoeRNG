# -*- coding: utf-8 -*-
"""空库 404 优雅化：bump 2.0.0-beta.5 → 2.0.0-beta.6 + CHANGELOG"""
import io

def load(p):
    with io.open(p, encoding='utf-8', newline='') as f:
        t = f.read()
    return t, ('\r\n' if '\r\n' in t else '\n')

def save(p, t, nl):
    t = t.replace('\r\n', '\n').replace('\n', nl)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)

# 1. bootstrap.php bump
p = r"E:\Projects\DouBao\MoeRNG\src\bootstrap.php"
t, nl = load(p)
old = (
    "// v2.0.0-beta.5: M2 晴空画册视觉重设计 —— 暖米白/炭紫黑双主题 token、珊瑚粉+鼠尾草青+蜜橘、思源宋体 display、思源黑体正文、圆角收敛去霓虹、前台 hero kicker、CSS 90KB 预算瘦身合并、CSP font-src 放行。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.5');\n"
    "}"
).replace('\n', nl)
new = (
    "// v2.0.0-beta.5: M2 晴空画册视觉重设计 —— 暖米白/炭紫黑双主题 token、珊瑚粉+鼠尾草青+蜜橘、思源宋体 display、思源黑体正文、圆角收敛去霓虹、前台 hero kicker、CSS 90KB 预算瘦身合并、CSP font-src 放行。\n"
    "// v2.0.0-beta.6: 空库 404 优雅化 —— /api/v1/random 空库/分类空返回 JSON 错误体（中文引导 + error 保持英文兼容）；tester 前端非 2xx 展示服务端 message / 网关提示；文档补充空库行为。\n"
    "if (!defined('APP_VERSION')) {\n"
    "    define('APP_VERSION', '2.0.0-beta.6');\n"
    "}"
).replace('\n', nl)
if old not in t:
    raise SystemExit('[FAIL] version anchor')
t = t.replace(old, new, 1)
save(p, t, nl)
print('[OK] bootstrap.php: 2.0.0-beta.6')

# 2. CHANGELOG 顶部插入
p = r"E:\Projects\DouBao\MoeRNG\CHANGELOG.md"
t, nl = load(p)
block = (
    "## [2.0.0-beta.6] - 2026-09-28\n"
    "\n"
    "> 空库 404 优雅化：无图/分类无图时的 API 与测试页表现从「裸 404」改为可读引导。\n"
    "\n"
    "### ✨ 改进\n"
    "\n"
    "- **`/api/v1/random` 空库响应**：图库或所选分类为空时仍返回 HTTP 404，但 body 为结构化 JSON —— `error` 保持英文机器码 `No images found`（既有调用方零破坏），新增 `code: NO_IMAGES_AVAILABLE` 与中文引导 `message`（「图库暂无可用图片…请先在管理后台上传」）；分类为用户可控输入，插入前已 HTML 转义\n"
    "- **在线测试页错误渲染**：JSON 分支对非 2xx 响应优雅化 —— 响应体是 JSON 时直接展示服务端 `message`/`error`；响应体非 JSON（nginx 默认错误页等）时提示「网关/伪静态（URL Rewrite）未配置，请求未到达应用层」并保留原始响应供诊断，不再裸显示 `404 Not Found / nginx`\n"
    "- **API 文档**：补充空库行为说明（404 + JSON 错误体语义）\n"
    "\n"
    "### ⚠️ 部署提示\n"
    "\n"
    "- 若线上请求 `/api/v1/random` 仍见 nginx 默认 404 页，根因是 Web 服务器未启用伪静态（URL Rewrite）：`src/nginx.conf.example` 已注明，`location /api { try_files $uri /api.php$is_args$args; }` 未配置时请求不会到达应用层（与图库是否为空无关）\n"
    "\n"
    "### ✅ 回归\n"
    "\n"
    "- syntax 2912/2912；API 契约 67 项、design_contract 63 项、hero_stats 36 项、page_matrix / click_matrix / scope / click / xref 全绿\n"
    "\n"
).replace('\n', nl)
anchor = "## [2.0.0-beta.5] - 2026-09-28"
if block.strip().replace('\n', nl) in t:
    print('[SKIP] beta.6 已存在')
elif anchor in t:
    t = t.replace(anchor, block + anchor, 1)
    save(p, t, nl)
    print('[OK] CHANGELOG beta.6')
else:
    raise SystemExit('[FAIL] CHANGELOG anchor')
