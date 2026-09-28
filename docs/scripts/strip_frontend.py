# -*- coding: utf-8 -*-
"""doctor.php + settings.php + admin.php 兼容层拆除（v2.0.0-beta.1）：
- doctor.php：删迁根检查段 / local_root_error 段 / Settings orphan keys 段
- settings.php：删「存储结构与迁移」面板；「清理存储残留」改述为「清理暂存残留」并删 verdict
- admin.php：删 migrate-layout 路由"""
import io

# ---------- doctor.php ----------
P = r"E:\Projects\DouBao\MoeRNG\src\doctor.php"
with io.open(P, encoding='utf-8') as f:
    t = f.read()

def cut(text, start_anchor, end_anchor, label):
    i = text.find(start_anchor)
    if i == -1:
        raise SystemExit(f"[FAIL] {label}: start anchor not found:\n{start_anchor[:90]!r}")
    j = text.find(end_anchor, i + len(start_anchor))
    if j == -1:
        raise SystemExit(f"[FAIL] {label}: end anchor not found after start")
    removed = text[i:j]
    print(f"[OK] {label}: removed {removed.count(chr(10))} lines")
    return text[:i] + text[j:]

# 1) 迁根收尾检查段
t = cut(
    t,
    "        // 迁根收尾检查：历史根里若仍有媒体（迁移未执行 / 失败），文件仍可读（不影响\n"
    "        // 站点），所以只做提示；首个请求会自动迁移，失败原因见「本地媒体根迁移」。\n",
    "    } catch (Throwable $e) {\n"
    "        check('LocalDriver init', false, $e->getMessage());",
    "doctor 1. 迁根收尾检查",
)

# 2) local_root_error / local_root_layout 检查段
t = cut(
    t,
    "            // v1.5.0-beta.1: 本地媒体根迁移是独立门禁（文件操作），单独上报。\n",
    "        } catch (Throwable) {\n"
    "            // settings table shape differs / unavailable — ignore\n"
    "        }",
    "doctor 2. local_root 检查",
)

# 3) Settings orphan keys 段
t = cut(
    t,
    "// v1.0.34-beta.2: detect orphan storage_* keys in `settings` once profiles\n",
    "// v1.2.0 迭代: signed links — local files now go through the /files endpoint;\n",
    "doctor 3. Settings orphan keys",
)

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[DONE] doctor.php")

# ---------- settings.php ----------
P = r"E:\Projects\DouBao\MoeRNG\src\views\admin\settings.php"
with io.open(P, encoding='utf-8') as f:
    t = f.read()

# 1) 删除「存储结构与迁移」面板
t = cut(
    t,
    "    <!-- v1.5.0-beta.1: 存储结构统一（方案 A）—— 存量对象迁移面板 -->\n",
    "    <!-- v1.5.0-beta.1: 清理历次更新留下的存储残留（干跑优先） -->\n",
    "settings 1. 迁移面板",
)

# 2) 「清理存储残留」面板 →「清理暂存残留」（替换 card 内全部内容到 </div> 结束）
start2 = "    <!-- v1.5.0-beta.1: 清理历次更新留下的存储残留（干跑优先） -->\n"
i = t.find(start2)
if i == -1:
    raise SystemExit("[FAIL] settings 2. 清理面板起点未命中")
j = t.find("    <!-- v1.5.0-beta.1: 历史原图批量转 WebP（干跑优先） -->\n", i)
if j == -1:
    raise SystemExit("[FAIL] settings 2. 清理面板终点未命中")
new_panel = (
    "    <!-- v2.0.0-beta.1: 清理暂存目录残留（干跑优先） -->\n"
    "    <div class=\"card mb-3 batch-panel\">\n"
    "        <h3>清理暂存残留</h3>\n"
    "        <p class=\"batch-summary\">清理 <code>storage/incoming</code> 里不再属于「在办」行的临时文件\n"
    "        （致命错误、清空队列等留下的）。默认<strong>干跑</strong>只出清单。</p>\n"
    "        <details class=\"batch-help\">\n"
    "            <summary>能力边界与「待清理 0 项」的含义</summary>\n"
    "            <p>\n"
    "                [注意] 记录已被删除的孤立对象（例如用过「清空队列」的那些）<strong>无法</strong>被本工具发现 ——\n"
    "                存储接口没有列举能力，需到对象存储控制台按前缀人工清理。\n"
    "            </p>\n"
    "            <p>\n"
    "                「待清理 0 项」是真的看过了（扫描数如实报告），而不是没看；仍处于\n"
    "                pending/processing/failed 的行对应的暂存文件会保留（可能正在处理）。\n"
    "            </p>\n"
    "        </details>\n"
    "        <p class=\"batch-stat\" id=\"cleanup-stat\">点击「干跑检查」查看待清理清单。</p>\n"
    "        <div class=\"batch-actions\">\n"
    "            <button type=\"button\" class=\"btn btn-outline btn-sm\" id=\"cleanup-check\">干跑检查</button>\n"
    "            <button type=\"button\" class=\"btn btn-danger btn-sm\" id=\"cleanup-run\">执行清理</button>\n"
    "        </div>\n"
    "        <div id=\"cleanup-progress\" class=\"hidden\">\n"
    "            <div class=\"health-progress-text\" id=\"cleanup-text\">准备检查…</div>\n"
    "            <div class=\"health-progress-track\"><div id=\"cleanup-fill\" class=\"health-progress-fill\"></div></div>\n"
    "            <div class=\"health-progress-detail\" id=\"cleanup-detail\"></div>\n"
    "        </div>\n"
    "    </div>\n\n"
)
t = t[:i] + new_panel + t[j:]
print("[OK] settings 2. 清理面板改述（仅暂存）")

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[DONE] settings.php")

# ---------- admin.php ----------
P = r"E:\Projects\DouBao\MoeRNG\src\admin.php"
with io.open(P, encoding='utf-8') as f:
    t = f.read()
old = (
    "    // v1.5.0-beta.1: 存量对象迁移到统一存储布局（方案 A；dry-run 默认，apply 逐资产原子）\n"
    "    $router->post('/images/migrate-layout', [$images, 'migrateLayout']);\n"
    "    // v1.5.0-beta.1: 清理旧布局对象 / 暂存垃圾 / 历史媒体根残留（干跑优先）\n"
    "    $router->post('/images/cleanup-storage', [$images, 'cleanupStorage']);\n"
)
new = (
    "    // v2.0.0-beta.1: 清理 storage/incoming 暂存残留（干跑优先）；\n"
    "    // v1.5.0-beta.1 的 migrate-layout / 旧布局清理已随「取消兼容与迁移」移除\n"
    "    $router->post('/images/cleanup-storage', [$images, 'cleanupStorage']);\n"
)
if old not in t:
    raise SystemExit("[FAIL] admin.php 路由锚点未命中")
t = t.replace(old, new, 1)
with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[OK] admin.php 路由移除 migrate-layout")
