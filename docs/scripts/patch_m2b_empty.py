# -*- coding: utf-8 -*-
"""空库 404 优雅化：ApiController 空库响应友好化 + tester 前端错误渲染增强 + 文档补充"""
import io

def load(p):
    with io.open(p, encoding='utf-8', newline='') as f:
        t = f.read()
    return t, ('\r\n' if '\r\n' in t else '\n')

def save(p, t, nl):
    t = t.replace('\r\n', '\n').replace('\n', nl)
    with io.open(p, 'w', encoding='utf-8', newline='') as f:
        f.write(t)

def rep(p, old, new, tag):
    t, nl = load(p)
    o, n = old.replace('\n', '\r\n'), new.replace('\n', '\r\n')
    if nl == '\n':
        o, n = old, new
    if o not in t:
        print('[FAIL] %s' % tag)
        return
    t = t.replace(o, n, 1)
    save(p, t, nl)
    print('[OK] %s' % tag)

# ── 1. ApiController::random 空库分支：友好 message + 稳定 code（error 字段不变，兼容既有调用方）──
p1 = r"E:\Projects\DouBao\MoeRNG\src\app\Controllers\ApiController.php"
rep(p1,
    "        if (!$image) {\n"
    "            $this->json([\n"
    "                'success' => false,\n"
    "                'error' => 'No images found',\n"
    "                'message' => 'No images available' . ($category ? \" in category '{$category}'\" : '') . '.',\n"
    "            ], 404);\n"
    "        }",
    "        if (!$image) {\n"
    "            // v2.0.0-beta.5: 空库 404 优雅化 —— error 字段保持英文机器码（兼容既有调用方），\n"
    "            // message 换为中文引导；category 为用户可控输入，插入 message 前必须 HTML 转义。\n"
    "            $this->json([\n"
    "                'success' => false,\n"
    "                'error' => 'No images found',\n"
    "                'code' => 'NO_IMAGES_AVAILABLE',\n"
    "                'message' => '图库暂无可用图片'\n"
    "                    . ($category ? '（分类 ' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . ' 下暂无图片）' : '')\n"
    "                    . '，请先在管理后台「图片管理」上传图片。',\n"
    "            ], 404);\n"
    "        }",
    'ApiController 空库响应友好化')

# ── 2. tester 前端 JSON 分支：非 2xx 响应展示服务端 message / 网关提示 ──
p2 = r"E:\Projects\DouBao\MoeRNG\src\public\js\app.js"
rep(p2,
    "                const resp = await fetch(apiPath, { cache: 'no-store' });\n"
    "                const ms = performance.now() - t0;\n"
    "                let text = await resp.text();\n"
    "                let pretty;\n"
    "                try { pretty = JSON.stringify(JSON.parse(text), null, 2); }\n"
    "                catch (e) { pretty = text; }\n"
    "                resultBox.innerHTML = '<pre>' + pretty + '</pre>';\n"
    "                showMeta(resp.status, resp.statusText || (resp.ok ? 'OK' : 'Error'), ms);\n"
    "                saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');\n"
    "                resolveRequest();",
    "                const resp = await fetch(apiPath, { cache: 'no-store' });\n"
    "                const ms = performance.now() - t0;\n"
    "                let text = await resp.text();\n"
    "                let pretty;\n"
    "                try { pretty = JSON.stringify(JSON.parse(text), null, 2); }\n"
    "                catch (e) { pretty = text; }\n"
    "                // v2.0.0-beta.5: 非 2xx 响应优雅化 —— JSON 错误体直接展示服务端 message\n"
    "                // （空库引导等）；非 JSON 体（nginx 默认错误页等）说明网关/伪静态未配置，\n"
    "                // 请求根本没到应用层，给可读提示而不是裸「404 Not Found / nginx」。\n"
    "                if (!resp.ok) {\n"
    "                    let hint = '';\n"
    "                    try { const j = JSON.parse(text); hint = (j && (j.message || j.error)) || ''; }\n"
    "                    catch (e2) { /* non-JSON body */ }\n"
    "                    resultBox.innerHTML =\n"
    "                        '<pre style=\"color:var(--danger)\">请求失败（HTTP ' + resp.status + '）'\n"
    "                        + (hint ? '\\n' + hint : '\\n接口返回了非 JSON 错误体 —— 多为网关/伪静态（URL Rewrite）未配置，请求未到达应用层。')\n"
    "                        + '\\n\\n原始响应：\\n' + pretty + '</pre>';\n"
    "                    showMeta(resp.status, resp.statusText || 'Error', ms);\n"
    "                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');\n"
    "                    resolveRequest();\n"
    "                } else {\n"
    "                    resultBox.innerHTML = '<pre>' + pretty + '</pre>';\n"
    "                    showMeta(resp.status, resp.statusText || 'OK', ms);\n"
    "                    saveTestHistory(apiPath, resp.status + ' ' + (resp.statusText || '') + ' (' + formatDuration(ms) + ')');\n"
    "                    resolveRequest();\n"
    "                }",
    'tester 前端错误渲染优雅化')

# ── 3. docs.php：补充空库行为说明（不动契约锁定文案）──
p3 = r"E:\Projects\DouBao\MoeRNG\src\views\docs.php"
rep(p3,
    "尺寸 -> md -> 原图 回退（绝不返回 404），响应里的 size 字段如实回报实际生效的尺寸，所以回退也能被察觉。",
    "尺寸 -> md -> 原图 回退（绝不返回 404），响应里的 size 字段如实回报实际生效的尺寸，所以回退也能被察觉。"
    "图库或所选分类为空时返回 404 与 JSON 错误体（message 含中文引导，error 保持英文机器码）。",
    'docs.php 补充空库行为说明')
