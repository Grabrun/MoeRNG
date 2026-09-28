# -*- coding: utf-8 -*-
"""4 个 harness 文件的 v2.0.0-beta.1 同步补丁（layout 已删 id/符号/断言）。"""
import io

def patch(path, old, new, label):
    with io.open(path, encoding='utf-8') as f:
        t = f.read()
    if old not in t:
        raise SystemExit(f"[FAIL] {label}: anchor not found")
    t = t.replace(old, new, 1)
    with io.open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(t)
    print(f"[OK] {label}")

# 1) click_matrix_test.js：settings 页 id 清单
patch(
    r"E:\Projects\DouBao\MoeRNG\.dsh\click_matrix_test.js",
    "    'layout-check', 'layout-migrate', 'layout-stat', 'layout-progress',\n"
    "    'layout-text', 'layout-fill', 'layout-detail',\n"
    "    'cleanup-check', 'cleanup-run', 'cleanup-stat', 'cleanup-verdict', 'cleanup-progress',",
    "    'cleanup-check', 'cleanup-run', 'cleanup-stat', 'cleanup-progress',",
    "click_matrix: settings id 清单",
)

# 2) design_contract_test.js：ids 数组 + phrases
patch(
    r"E:\Projects\DouBao\MoeRNG\.dsh\design_contract_test.js",
    "  const ids = ['layout-stat', 'layout-check', 'layout-migrate', 'layout-progress', 'layout-text', 'layout-fill', 'layout-detail',\n"
    "    'cleanup-stat', 'cleanup-verdict', 'cleanup-check', 'cleanup-run', 'cleanup-progress', 'cleanup-text', 'cleanup-fill', 'cleanup-detail',",
    "  const ids = [\n"
    "    'cleanup-stat', 'cleanup-check', 'cleanup-run', 'cleanup-progress', 'cleanup-text', 'cleanup-fill', 'cleanup-detail',",
    "design_contract: ids 数组",
)
patch(
    r"E:\Projects\DouBao\MoeRNG\.dsh\design_contract_test.js",
    "  const phrases = ['无法', '被本工具发现', '新上传的原图已自动转 WebP', '只换扩展名、布局不动', '全表扫描', '干跑'];",
    "  const phrases = ['无法', '被本工具发现', '新上传的原图已自动转 WebP', '只换扩展名、布局不动', '干跑'];",
    "design_contract: phrases",
)

# 3) convert_contract_test.js：heavy 端点列表删 migrateLayout
patch(
    r"E:\Projects\DouBao\MoeRNG\.dsh\convert_contract_test.js",
    "    ['cleanupStorage',  'public function cleanupStorage(Request $request): void'],\n"
    "    ['migrateLayout',   'public function migrateLayout(Request $request): void'],\n",
    "    ['cleanupStorage',  'public function cleanupStorage(Request $request): void'],\n",
    "convert_contract: heavy 列表",
)

# 4) perf_contract_test.js：/files 快路径断言
patch(
    r"E:\Projects\DouBao\MoeRNG\.dsh\perf_contract_test.js",
    "  // —— 9d. /files 快路径：命中默认根/历史根时不查库 ——\n"
    "  const show = methodBody(FC, 'public function show(Request $request): void');\n"
    "  ok('快路径零查询（默认根 + 历史根先行）',\n"
    "     /\\[LocalDriver::defaultUploadDir\\(), LocalDriver::legacyUploadDir\\(\\)\\],\\s*\\n\\s*\\$relative/.test(show));",
    "  // —— 9d. /files 快路径：命中默认根时不查库 ——\n"
    "  const show = methodBody(FC, 'public function show(Request $request): void');\n"
    "  ok('快路径零查询（默认根先行；v2.0.0 起不再有历史根）',\n"
    "     /locateIn\\(\\[LocalDriver::defaultUploadDir\\(\\)\\], \\$relative\\)/.test(show));",
    "perf_contract: /files 快路径",
)

# 5) audit_data_security.js：注释不再指向已删的 migrateLayout
patch(
    r"E:\Projects\DouBao\MoeRNG\.dsh\audit_data_security.js",
    "//   $fill 为 limit 与已取行数的整数差值；$legacyWhere 见 ImageController::migrateLayout ——\n"
    "//   判据只由字面量拼装（path 形态），无用户输入；$whereSql 见 SettingController::logs —— WHERE 子句只由",
    "//   $fill 为 limit 与已取行数的整数差值；$legacyWhere 曾见 ImageController::migrateLayout\n"
    "//   （v2.0.0-beta.1 已删除，变量不再出现）；$whereSql 见 SettingController::logs —— WHERE 子句只由",
    "audit_data_security: 注释",
)

print("[DONE] all patches applied")
