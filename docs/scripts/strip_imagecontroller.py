# -*- coding: utf-8 -*-
"""ImageController.php 兼容层拆除（v2.0.0-beta.1）：
1) 删除 migrateLayout + copyObjectWithinDriver
2) cleanupStorage 重构为「仅暂存清理」，删除 cleanupDiagnostics/cleanupLegacyObjects/cleanupLegacyRootFiles
3) queueClear / convertOriginals 注释同步
每步锚点精确匹配，失败即中止。"""
import io

P = r"E:\Projects\DouBao\MoeRNG\src\app\Controllers\Admin\ImageController.php"
with io.open(P, encoding='utf-8') as f:
    t = f.read()
orig_len = len(t)

def cut(text, start_anchor, end_anchor, label, keep_end=True):
    i = text.find(start_anchor)
    if i == -1:
        raise SystemExit(f"[FAIL] {label}: start anchor not found:\n{start_anchor[:100]!r}")
    j = text.find(end_anchor, i + len(start_anchor))
    if j == -1:
        raise SystemExit(f"[FAIL] {label}: end anchor not found after start")
    removed = text[i:j]
    print(f"[OK] {label}: removed {removed.count(chr(10))} lines")
    return text[:i] + text[j:]

# 1) migrateLayout + copyObjectWithinDriver
t = cut(
    t,
    "    /**\n"
    "     * v1.5.0-beta.1 存储结构统一（方案 A）: POST /admin/images/migrate-layout\n",
    "    /**\n"
    "     * v1.3.3-beta.2 增强: POST /admin/images/requeue-one",
    "1. migrateLayout+copyObjectWithinDriver",
)

# 2) cleanup 区段整体替换（常量 + docblock + cleanupStorage 函数）
start2 = (
    "    /* ------------------------------------------------------------------\n"
    "     * v1.5.0-beta.1: 存储残留清理（干跑优先）\n"
    "     * ------------------------------------------------------------------ */\n"
)
i = t.find(start2)
if i == -1:
    raise SystemExit("[FAIL] 2. cleanup 区段起点未命中")
j = t.find("    /**\n     * ③ 诊断：把", i)
if j == -1:
    raise SystemExit("[FAIL] 2. cleanup 区段终点未命中")
t = t[:i] + t[j:]

new_block = (
    "    /* ------------------------------------------------------------------\n"
    "     * v2.0.0-beta.1: 暂存目录清理（干跑优先）\n"
    "     * ------------------------------------------------------------------ */\n"
    "\n"
    "    /** 每批处理的条目上限（行 / 文件），防止一次请求做太多删除。 */\n"
    "    private const CLEANUP_BATCH_MAX = 50;\n"
    "\n"
    "    /**\n"
    "     * POST /admin/images/cleanup-storage —— 清理 `storage/incoming` 暂存目录里\n"
    "     * 不再属于「在办」行的临时文件（致命错误、清空队列等留下的）。\n"
    "     *\n"
    "     * v1.5.0-beta.1 的「旧布局对象 / 历史媒体根」两类清理已随「取消兼容与迁移」\n"
    "     * 整体移除（旧布局与旧根不再存在）；「记录已删」的孤立对象仍只能到对象存储\n"
    "     * 控制台按前缀人工清理（`StorageInterface` 没有 LIST 能力，UI 与文档如实说明）。\n"
    "     *\n"
    "     * 安全护栏：暂存文件对应的行若处于 pending/processing/failed 则保留\n"
    "     * （那个文件可能正被另一个请求使用）。\n"
    "     *\n"
    "     * `mode=dry-run`（默认）只出清单、**不写任何状态**；`apply` 才真正删除。\n"
    "     */\n"
    "    public function cleanupStorage(Request $request): void\n"
    "    {\n"
    "        $this->validateCsrf();\n"
    "        // v2.0.0-beta.1: 重型端点必须把致命错误（OOM / 超时）变成可读 JSON。\n"
    "        $this->jsonFatalGuard('cleanup-storage');\n"
    "        $mode = (string) $request->input('mode', 'dry-run');\n"
    "        if (!in_array($mode, ['dry-run', 'apply'], true)) {\n"
    "            $this->json(['success' => false, 'error' => '无效的清理模式'], 400);\n"
    "            return;\n"
    "        }\n"
    "        $apply = $mode === 'apply';\n"
    "        $batch = max(1, min(self::CLEANUP_BATCH_MAX, (int) $request->input('batch', '10')));\n"
    "\n"
    "        try {\n"
    "            $pdo = \\App\\Core\\Database::getInstance();\n"
    "        } catch (\\Throwable $e) {\n"
    "            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);\n"
    "            return;\n"
    "        }\n"
    "\n"
    "        try {\n"
    "            $staging = $this->cleanupStagingFiles($pdo, $batch, $apply);\n"
    "        } catch (\\Throwable $e) {\n"
    "            $this->json(['success' => false, 'error' => '清理失败: ' . $e->getMessage()], 500);\n"
    "            return;\n"
    "        }\n"
    "\n"
    "        $this->json([\n"
    "            'success'   => true,\n"
    "            'mode'      => $mode,\n"
    "            'staging'   => $staging,\n"
    "            'remaining' => $staging['remaining'],\n"
    "        ]);\n"
    "    }\n"
    "\n"
)
t = t[:i] + new_block + t[i:]
print("[OK] 2. cleanupStorage 重构（仅暂存）")

# 3) 删除 cleanupDiagnostics
t = cut(
    t,
    "    /**\n"
    "     * ③ 诊断：把",
    "    /**\n"
    "     * ① 旧布局对象清理（按行推进",
    "3. cleanupDiagnostics",
)

# 4) 删除 cleanupLegacyObjects
t = cut(
    t,
    "    /**\n"
    "     * ① 旧布局对象清理（按行推进",
    "    /**\n"
    "     * ② 暂存目录垃圾",
    "4. cleanupLegacyObjects",
)

# 5) 删除 cleanupLegacyRootFiles
t = cut(
    t,
    "    /**\n"
    "     * ③ 历史媒体根残留：public/uploads 下没有任何记录引用的文件。",
    "    /**\n"
    "     * 列出目录下的文件（相对根的绝对路径），按路径排序保证可预期。",
    "5. cleanupLegacyRootFiles",
)

# 6) queueClear 注释同步
old_q = (
    "     * 补充（v1.5.0-beta.1）：这类**记录已删**的孤立对象无法被 cleanupStorage()\n"
    "     * 发现（存储接口没有 LIST 能力），只能到对象存储控制台按前缀人工清理；\n"
    "     * cleanupStorage() 负责的是记录仍在、但旧键/暂存/无引用文件等**可确定性判定**\n"
    "     * 的残留。"
)
new_q = (
    "     * 补充（v2.0.0-beta.1）：这类**记录已删**的孤立对象无法被 cleanupStorage()\n"
    "     * 发现（存储接口没有 LIST 能力），只能到对象存储控制台按前缀人工清理；\n"
    "     * cleanupStorage() 只负责 storage/incoming 里**可确定性判定**的暂存残留。"
)
if old_q not in t:
    raise SystemExit("[FAIL] 6. queueClear 注释锚点未命中")
t = t.replace(old_q, new_q, 1)
print("[OK] 6. queueClear 注释同步")

# 7) convertOriginals 注释同步
old_c = (
    "     * 键怎么变：**只换扩展名、布局不动** —— 新布局 `{dir}/original.{ext}` →\n"
    "     * `{dir}/original.webp`；旧布局 `{Y}/{m}/{uuid}.{ext}` → `{Y}/{m}/{uuid}.webp`。\n"
    "     * 两种布局下**缩略图键都与原图扩展名无关**（新布局是同目录固定名 `thumb-{size}.webp`，\n"
    "     * 旧布局是 `thumbs/…/{uuid}.webp`），所以缩略图完全不需要搬迁。"
)
new_c = (
    "     * 键怎么变：**只换扩展名、布局不动** —— `{dir}/original.{ext}` →\n"
    "     * `{dir}/original.webp`。缩略图键与原图扩展名无关（同目录固定名\n"
    "     * `thumb-{size}.webp`），所以缩略图完全不需要搬迁。"
)
if old_c not in t:
    raise SystemExit("[FAIL] 7. convertOriginals 注释锚点未命中")
t = t.replace(old_c, new_c, 1)
print("[OK] 7. convertOriginals 注释同步")

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print(f"\n[DONE] {orig_len} -> {len(t)} chars (removed {orig_len - len(t)})")
