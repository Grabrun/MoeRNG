# -*- coding: utf-8 -*-
"""Application.php backfill 段：旧设置键读取 → 默认 profile 推导（v2.0.0 唯一来源）"""
import io

p = r"E:\Projects\DouBao\MoeRNG\src\app\Core\Application.php"
with io.open(p, encoding='utf-8') as f:
    t = f.read()

old = (
    "        // Backfill existing rows with the current global driver/provider so\n"
    "        // historically-stored files keep resolving after a driver switch.\n"
    "        try {\n"
    "            $driver = (string) \\App\\Core\\Config::get('settings.storage_driver', 'local');\n"
    "            $provider = (string) \\App\\Core\\Config::get('settings.storage_s3_provider', 'cos');\n"
    "            $db->exec(\n"
    "                \"UPDATE `images` SET `storage` = \" . $db->quote($driver)\n"
    "                . \" WHERE `storage` = '' OR `storage` IS NULL\"\n"
    "            );\n"
    "            if ($provider !== '') {\n"
    "                $db->exec(\n"
    "                    \"UPDATE `images` SET `storage_provider` = \" . $db->quote($provider)\n"
    "                    . \" WHERE `storage` = 's3' AND (`storage_provider` = '' OR `storage_provider` IS NULL)\"\n"
    "                );\n"
    "            }\n"
    "        } catch (\\Throwable $e) {\n"
    "            return 'backfill existing rows failed: ' . $e->getMessage();\n"
    "        }"
)
new = (
    "        // Backfill existing rows with the default profile's driver/provider so\n"
    "        // historically-stored files keep resolving after a driver switch.\n"
    "        // v2.0.0-beta.1: storage_profiles is the single source of truth — the\n"
    "        // legacy settings keys (settings.storage_driver / storage_s3_provider)\n"
    "        // are no longer read or written anywhere.\n"
    "        try {\n"
    "            $profile = \\App\\Models\\StorageProfile::defaultProfile();\n"
    "            $driver = $profile !== null ? $profile->driver : 'local';\n"
    "            $provider = $profile !== null ? (string) $profile->provider : '';\n"
    "            $db->exec(\n"
    "                \"UPDATE `images` SET `storage` = \" . $db->quote($driver)\n"
    "                . \" WHERE `storage` = '' OR `storage` IS NULL\"\n"
    "            );\n"
    "            if ($provider !== '') {\n"
    "                $db->exec(\n"
    "                    \"UPDATE `images` SET `storage_provider` = \" . $db->quote($provider)\n"
    "                    . \" WHERE `storage` = 's3' AND (`storage_provider` = '' OR `storage_provider` IS NULL)\"\n"
    "                );\n"
    "            }\n"
    "        } catch (\\Throwable $e) {\n"
    "            return 'backfill existing rows failed: ' . $e->getMessage();\n"
    "        }"
)
if old not in t:
    raise SystemExit("[FAIL] Application.php backfill anchor not found")
t = t.replace(old, new, 1)
with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print("[OK] Application.php backfill 已改为默认 profile 推导")
