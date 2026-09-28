# -*- coding: utf-8 -*-
"""app.js 前端兼容层拆除（v2.0.0-beta.1）：
1) 删除 runLayoutMigration + sumCleanupResponse + initLayoutMigration
2) runStorageCleanup 重写为「仅暂存清理」
3) initStorageCleanup 更新（去 counterLine/verdict/diag 语义）
4) init 接线删除 initLayoutMigration()"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\public\js\app.js"
with io.open(P, encoding='utf-8') as f:
    t = f.read()
orig_len = len(t)

def cut(text, start_anchor, end_anchor, label):
    i = text.find(start_anchor)
    if i == -1:
        raise SystemExit(f"[FAIL] {label}: start anchor not found:\n{start_anchor[:100]!r}")
    j = text.find(end_anchor, i + len(start_anchor))
    if j == -1:
        raise SystemExit(f"[FAIL] {label}: end anchor not found after start")
    removed = text[i:j]
    print(f"[OK] {label}: removed {removed.count(chr(10))} lines")
    return text[:i] + text[j:]

# 1) 删除 runLayoutMigration（整函数）
t = cut(
    t,
    "// ── v1.5.0-beta.1: 存储结构统一（方案 A）—— 存量对象迁移 ────────────────\n"
    "// dry-run 只返回计划（不写对象、不改库）；apply 逐资产原子：复制并校验 → 改库记录 →\n"
    "// 最后删旧对象，因此迁移过程可随时中断，图片始终可访问。\n"
    "async function runLayoutMigration(setUI) {",
    "// ── v1.5.0-beta.1: 存储残留清理（干跑优先）────────────────────────────\n",
    "1. runLayoutMigration",
)

# 2) 删除 sumCleanupResponse（整函数，紧跟在 runLayoutMigration 之后）
t = cut(
    t,
    "// 三类残留：① 旧布局对象 ② storage/incoming 暂存垃圾 ③ public/uploads 无引用文件。\n"
    "// 干跑只跑一轮并返回清单；执行清理按批循环直到 remaining 收敛。\n"
    "function sumCleanupResponse(j) {",
    "async function runStorageCleanup(mode, setUI) {",
    "2. sumCleanupResponse",
)

# 3) runStorageCleanup 重写：整函数替换（从 async function runStorageCleanup 到 initStorageCleanup 前）
i = t.find("async function runStorageCleanup(mode, setUI) {")
if i == -1:
    raise SystemExit("[FAIL] 3. runStorageCleanup 起点未命中")
j = t.find("function initStorageCleanup() {", i)
if j == -1:
    raise SystemExit("[FAIL] 3. runStorageCleanup 终点未命中")
new_rsc = (
    "// ── v2.0.0-beta.1: 暂存目录清理（干跑优先）────────────────────────────\n"
    "// 只清 storage/incoming 里不再属于「在办」行的临时文件。干跑单轮出全量清单；\n"
    "// 执行清理按批循环，直到 remaining 收敛（服务端每轮重扫目录，剩余数递减）。\n"
    "async function runStorageCleanup(mode, setUI) {\n"
    "    const apply = mode === 'apply';\n"
    "    let done = 0, planned = 0, failed = 0, skipped = 0, scanned = 0, rounds = 0;\n"
    "    let firstError = '';\n"
    "    const samples = [];\n"
    "\n"
    "    for (;;) {\n"
    "        rounds++;\n"
    "        const fd = new FormData();\n"
    "        fd.append('_csrf_token', getCsrfToken());\n"
    "        fd.append('mode', apply ? 'apply' : 'dry-run');\n"
    "        fd.append('batch', apply ? '10' : '1000');\n"
    "\n"
    "        const r = await fetch('/admin/images/cleanup-storage', {\n"
    "            method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' },\n"
    "        });\n"
    "        const j = await parseJsonResponse(r, '存储清理');\n"
    "        if (!j || !j.success) {\n"
    "            throw new Error((j && j.error) || '未知错误');\n"
    "        }\n"
    "\n"
    "        const s = j.staging || {};\n"
    "        scanned += Number(s.scanned) || 0;\n"
    "        done += apply ? (Number(s.deleted) || 0) : (Number(s.planned) || 0);\n"
    "        planned += Number(s.planned) || 0;\n"
    "        failed += Number(s.failed) || 0;\n"
    "        skipped += Number(s.skipped) || 0;\n"
    "        for (const item of s.samples || []) {\n"
    "            if (samples.length < 8) samples.push(item);\n"
    "        }\n"
    "\n"
    "        const remaining = Number(s.remaining) || 0;\n"
    "        setUI(remaining === 0 ? 1 : Math.min(0.95, scanned / Math.max(1, scanned + remaining)),\n"
    "            (apply ? '清理中… 已删除 ' : '检查中… 待清理 ') + done + ' 项',\n"
    "            '已扫描 ' + scanned + ' 个文件' + (remaining > 0 ? ' · 剩余 ' + remaining + ' 个' : ''));\n"
    "\n"
    "        if (remaining === 0) break;\n"
    "        if (!apply) break;  // 干跑单轮出全量清单（重扫会重复计数）\n"
    "        if ((Number(s.deleted) || 0) === 0) {\n"
    "            firstError = '目录无推进（文件被占用？），已中止';\n"
    "            break;\n"
    "        }\n"
    "        if (rounds > 500) {\n"
    "            firstError = '达到轮次上限（500），已中止';\n"
    "            break;\n"
    "        }\n"
    "    }\n"
    "\n"
    "    return { done: done, planned: planned, failed: failed, skipped: skipped,\n"
    "        scanned: scanned, samples: samples, firstError: firstError, rounds: rounds };\n"
    "}\n\n"
)
t = t[:i] + new_rsc + t[j:]

# 4) initStorageCleanup 更新：删除 counterLine + verdict 语义，重写函数体
i = t.find("function initStorageCleanup() {")
if i == -1:
    raise SystemExit("[FAIL] 4. initStorageCleanup 起点未命中")
j = t.find("function initLayoutMigration() {", i)
if j == -1:
    raise SystemExit("[FAIL] 4. initStorageCleanup 终点未命中（initLayoutMigration 未找到）")
new_isc = (
    "function initStorageCleanup() {\n"
    "    const checkBtn = document.getElementById('cleanup-check');\n"
    "    const runBtn = document.getElementById('cleanup-run');\n"
    "    if (!checkBtn && !runBtn) return;\n"
    "\n"
    "    const statEl = document.getElementById('cleanup-stat');\n"
    "    const box = document.getElementById('cleanup-progress');\n"
    "    const fill = document.getElementById('cleanup-fill');\n"
    "    const text = document.getElementById('cleanup-text');\n"
    "    const detail = document.getElementById('cleanup-detail');\n"
    "    const setUI = function (pct, t, d) {\n"
    "        if (box) box.classList.remove('hidden');\n"
    "        if (fill) fill.style.width = Math.min(100, Math.round(pct * 100)) + '%';\n"
    "        if (text && t) text.textContent = t;\n"
    "        if (detail && d !== undefined) detail.textContent = d;\n"
    "    };\n"
    "\n"
    "    // 清单里挑前几条人话描述（路径 / 跳过原因）\n"
    "    function describe(found) {\n"
    "        const parts = [];\n"
    "        for (const item of found) {\n"
    "            if (!item) continue;\n"
    "            if (item.path) parts.push(item.path);\n"
    "            else if (item.note) parts.push(item.note);\n"
    "            if (parts.length >= 3) break;\n"
    "        }\n"
    "        return parts.join('；');\n"
    "    }\n"
    "\n"
    "    checkBtn?.addEventListener('click', async function () {\n"
    "        checkBtn.disabled = true;\n"
    "        try {\n"
    "            const res = await runStorageCleanup('dry-run', setUI);\n"
    "            statEl.textContent = '待清理 ' + res.planned + ' 项 · 扫描 ' + res.scanned + ' 个文件';\n"
    "            const sample = describe(res.samples);\n"
    "            if (sample && res.planned > 0) statEl.textContent += '。示例：' + sample;\n"
    "            setUI(1, '检查完成', '待清理 ' + res.planned + ' 项 · 扫描 ' + res.scanned + ' 个文件');\n"
    "            if (runBtn) runBtn.disabled = res.planned === 0;\n"
    "            showToast('干跑完成：扫描 ' + res.scanned + ' 个文件，待清理 ' + res.planned + ' 项', 'success', 7000);\n"
    "        } catch (e) {\n"
    "            statEl.textContent = '检查失败：' + e.message;\n"
    "            showToast('干跑失败: ' + e.message, 'error', 8000);\n"
    "        } finally {\n"
    "            checkBtn.disabled = false;\n"
    "        }\n"
    "    });\n"
    "\n"
    "    // 两段式确认：执行清理会真正删除暂存文件\n"
    "    const label = runBtn ? runBtn.textContent : '';\n"
    "    let armed = false, timer = null;\n"
    "    runBtn?.addEventListener('click', async function () {\n"
    "        if (!armed) {\n"
    "            armed = true;\n"
    "            runBtn.textContent = '再次点击确认执行清理';\n"
    "            timer = setTimeout(function () { armed = false; runBtn.textContent = label; }, 6000);\n"
    "            return;\n"
    "        }\n"
    "        clearTimeout(timer);\n"
    "        armed = false;\n"
    "        runBtn.textContent = label;\n"
    "        runBtn.disabled = true;\n"
    "        try {\n"
    "            const res = await runStorageCleanup('apply', setUI);\n"
    "            statEl.textContent = '已删除 ' + res.done + ' 项（失败 ' + res.failed + '，跳过 ' + res.skipped\n"
    "                + '，扫描 ' + res.scanned + ' 个文件）' + (res.firstError ? '。' + res.firstError : '。');\n"
    "            setUI(1, '清理完成', '已删除 ' + res.done + ' 项');\n"
    "            showToast('清理完成：删除 ' + res.done + ' 项'\n"
    "                + (res.failed ? '，失败 ' + res.failed + ' 项' : ''), res.failed ? 'error' : 'success', 8000);\n"
    "            if (res.firstError) showToast(res.firstError, 'error', 9000);\n"
    "        } catch (e) {\n"
    "            statEl.textContent = '清理失败：' + e.message;\n"
    "            showToast('清理失败: ' + e.message, 'error', 9000);\n"
    "        } finally {\n"
    "            runBtn.disabled = false;\n"
    "        }\n"
    "    });\n"
    "}\n\n"
)
t = t[:i] + new_isc + t[j:]

# 5) 删除 initLayoutMigration（整函数）
t = cut(
    t,
    "function initLayoutMigration() {",
    "async function runHashBackfill(setUI) {",
    "5. initLayoutMigration",
)

# 6) init 接线删除 initLayoutMigration()
old_init = "    initLayoutMigration();\n    initStorageCleanup();\n    initOriginalConversion();"
new_init = "    initStorageCleanup();\n    initOriginalConversion();"
if old_init not in t:
    raise SystemExit("[FAIL] 6. init 接线锚点未命中")
t = t.replace(old_init, new_init, 1)
print("[OK] 6. init 接线移除 initLayoutMigration")

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print(f"\n[DONE] {orig_len} -> {len(t)} chars (removed {orig_len - len(t)})")
