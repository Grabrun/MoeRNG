# -*- coding: utf-8 -*-
"""v2.0.0-beta.2: Application.php — SCHEMA_VERSION bump + api_stats.fail 列（幂等，行级定位）"""
import io, re

p = r"E:\Projects\DouBao\MoeRNG\src\app\Core\Application.php"
with io.open(p, encoding='utf-8', newline='') as f:
    t = f.read()
nl = '\r\n' if '\r\n' in t else '\n'

# 1) SCHEMA_VERSION bump
t2 = t.replace("private const SCHEMA_VERSION = '2026-09-28';",
               "private const SCHEMA_VERSION = '2026-09-28-2';", 1)
if t2 == t:
    raise SystemExit('[FAIL] SCHEMA_VERSION anchor not found')
t = t2

# 2) api_stats 建表块：从 CREATE 行到其后的 $db->exec($sql);
lines = t.split(nl)
start = None
end = None
for i, ln in enumerate(lines):
    if 'CREATE TABLE IF NOT EXISTS `api_stats`' in ln:
        start = i
    if start is not None and i > start and ln.strip() == '$db->exec($sql);':
        end = i
        break
if start is None or end is None:
    raise SystemExit(f'[FAIL] api_stats block not found: start={start} end={end}')

indent = lines[start][:len(lines[start]) - len(lines[start].lstrip())]
block = [
    indent + '$sql = "CREATE TABLE IF NOT EXISTS `api_stats` (',
    indent + '    `day` DATE PRIMARY KEY,',
    indent + '    `count` INT UNSIGNED NOT NULL DEFAULT 0,',
    indent + '    `fail` INT UNSIGNED NOT NULL DEFAULT 0',
    indent + ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";',
    indent + '$db->exec($sql);',
    indent + '',
    indent + '// v2.0.0-beta.2: 可用性统计需要失败计数列。老库由运行时迁移补齐',
    indent + '// （SHOW COLUMNS probe → ALTER，吞 duplicate-column）；新库建表已带。',
    indent + '// ALTER 权限被拒时降级为「只有成功计数」（fail 恒 0），站点不受影响。',
    indent + 'try {',
    indent + '    $colProbe = $db->query("SHOW COLUMNS FROM `api_stats` LIKE \'fail\'");',
    indent + '    $hasFail = $colProbe !== false && $colProbe->fetch() !== false;',
    indent + '    if (!$hasFail) {',
    indent + '        $db->exec("ALTER TABLE `api_stats` ADD COLUMN `fail` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `count`");',
    indent + '    }',
    indent + '} catch (\\Throwable $e) {',
    indent + '    // ALTER-permission denial on a hosted account — availability degrades',
    indent + '    // to success-only (fail stays 0); site still works.',
    indent + '}',
]
lines[start:end + 1] = block
t = nl.join(lines)

with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(t)
print('[OK] Application.php: SCHEMA_VERSION=2026-09-28-2 + api_stats.fail 幂等迁移')
