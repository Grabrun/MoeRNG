# -*- coding: utf-8 -*-
"""Application.php 迁移链拆除（v2.0.0-beta.1）：删除本地根迁移链 + 凭据/Profile 迁移函数。
每步以锚点字符串定位，找不到即抛错中止，绝不静默部分删除。"""
import io, sys

P = r"E:\Projects\DouBao\MoeRNG\src\app\Core\Application.php"
with io.open(P, encoding='utf-8') as f:
    t = f.read()
orig_len = len(t)

def cut(text, start_anchor, end_anchor, label):
    i = text.find(start_anchor)
    if i == -1:
        raise SystemExit(f"[FAIL] {label}: start anchor not found: {start_anchor[:60]!r}")
    j = text.find(end_anchor, i + len(start_anchor))
    if j == -1:
        raise SystemExit(f"[FAIL] {label}: end anchor not found after start")
    removed = text[i:j]
    print(f"[OK] {label}: removed {removed.count(chr(10))} lines")
    return text[:i] + text[j:]

# 1) SCHEMA_VERSION bump + 删除 LOCAL_ROOT_LAYOUT 常量
t = cut(
    t,
    "    private const SCHEMA_VERSION = '2026-09-23';\n\n"
    "    /**\n"
    "     * v1.5.0-beta.1: 本地媒体根布局版本。站点迁到 `storage/uploads`（web 根之外）\n"
    "     * 后写入该值 —— 与 SCHEMA_VERSION 分开，避免文件迁移的失败连累 DB 迁移重跑。\n"
    "     */\n"
    "    private const LOCAL_ROOT_LAYOUT = '2';\n\n",
    "    private static ?self $instance = null;",
    "1. LOCAL_ROOT_LAYOUT 常量",
)
t = t.replace(
    "    private const SCHEMA_VERSION = '2026-09-23';",
    "    private const SCHEMA_VERSION = '2026-09-28';",
    1,
)

# 2) bootstrap 门禁删除
t = cut(
    t,
    "            // v1.5.0-beta.1: 本地媒体根迁出 web 根。独立门禁 —— 文件操作与 DB\n"
    "            // 迁移互不影响：成功即打标（此后零开销），失败不打标 → 下个请求\n"
    "            // 重试，错误经 doctor.php 可见（settings.local_root_error）。\n"
    "            if ((string) Config::get('settings.local_root_layout', '') !== self::LOCAL_ROOT_LAYOUT) {\n"
    "                $this->runLocalRootMigration();\n"
    "            }\n\n",
    "            // v1.2.1: storage profiles are loaded",
    "2. bootstrap 本地根门禁",
)

# 3) runStorageMigration 注释更新
old_comment = (
    "     * One-time backfills that must run after a code upgrade:\n"
    "     *\n"
    "     *  1. Per-image storage columns (v1.0.13) so switching the global driver\n"
    "     *     never orphans previously uploaded images.\n"
    "     *  2. Isolated per-provider credentials (v1.0.14): convert a pre-v1.0.14\n"
    "     *     single shared S3 credential set into the `storage_providers` JSON\n"
    "     *     map and set `storage_default_provider`, so every provider keeps its\n"
    "     *     own Access Key / Secret Key instead of overwriting each other.\n"
    "     *\n"
)
new_comment = (
    "     * One-time backfills that must run after a code upgrade:\n"
    "     *\n"
    "     *  1. Per-image storage columns (v1.0.13) so switching the global driver\n"
    "     *     never orphans previously uploaded images.\n"
    "     *  2. Storage profiles table + images.storage_profile_id (v1.0.33).\n"
    "     *     v2.0.0-beta.1: 旧凭据迁移（storage_s3_* → profiles）已随「取消兼容与迁移」\n"
    "     *     整体移除 —— `storage_profiles` 是唯一配置来源。\n"
    "     *\n"
)
if old_comment not in t:
    raise SystemExit("[FAIL] 3. runStorageMigration 注释锚点未命中")
t = t.replace(old_comment, new_comment, 1)
print("[OK] 3. runStorageMigration 注释更新")

# 4) runStorageMigration 删迁移函数调用
old_calls = (
    "            $this->migrateProviderCredentials();\n"
    "            // v1.0.33: storage profiles (multi-instance storage config).\n"
    "            $this->ensureStorageProfileTable($db);\n"
    "            $migrationError .= $this->ensureImageProfileColumn($db);\n"
    "            $this->migrateLegacyToProfiles();\n"
)
new_calls = (
    "            // v1.0.33: storage profiles (multi-instance storage config).\n"
    "            $this->ensureStorageProfileTable($db);\n"
    "            $migrationError .= $this->ensureImageProfileColumn($db);\n"
)
if old_calls not in t:
    raise SystemExit("[FAIL] 4. 迁移函数调用锚点未命中")
t = t.replace(old_calls, new_calls, 1)
print("[OK] 4. runStorageMigration 删除迁移函数调用")

# 5) 本地根迁移链整段删除（runLocalRootMigration → revertLocalProfilePaths）
t = cut(
    t,
    "    /**\n"
    "     * v1.5.0-beta.1: 本地媒体根从 web 根之下（public/uploads）迁到 web 根之外\n",
    "    /**\n"
    "     * Ensure the per-image storage columns exist on the `images` table.\n",
    "5. 本地根迁移链",
)

# 6) migrateProviderCredentials 整段删除
t = cut(
    t,
    "    /**\n"
    "     * v1.0.14: move from one shared S3 credential set (storage_s3_*) to an\n",
    "    /**\n"
    "     * v1.0.33: create the storage_profiles table (multi-instance storage\n",
    "6. migrateProviderCredentials",
)

# 7) migrateLegacyToProfiles / insertProfile / hasDefaultProfile 整段删除
t = cut(
    t,
    "    /**\n"
    "     * v1.0.33: seed storage_profiles from the legacy settings-store (only\n",
    "    public function router(): Router\n",
    "7. migrateLegacyToProfiles 链",
)

with io.open(P, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print(f"\n[DONE] {orig_len} -> {len(t)} chars (removed {orig_len - len(t)})")
