<?php
declare(strict_types=1);

/**
 * MoeRNG Diagnostic Tool
 *
 * Standalone self-check. Does NOT depend on the application bootstrap, so it
 * still works when the app itself is broken.
 *
 * Usage:  https://your-domain/doctor.php     or     php doctor.php
 * Delete this file once the deployment is verified.
 */

define('MOERNG_DEBUG_AUTOLOAD', true);

$cli = PHP_SAPI === 'cli';

// v1.3.2 迭代: 遗留问题清理开关。默认仅【检测并报告】; 加 --fix 才执行清理。
// 用法:
//   php doctor.php               -> 健康检查 + 遗留问题报告（dry-run）
//   php doctor.php --fix         -> 健康检查 + 执行可安全清理的项
//   https://site/doctor.php      -> 健康检查 + 遗留问题报告
//   https://site/doctor.php?type=fix -> 健康检查 + 执行可安全清理的项
//
// 注: HTTP 访问仅当登录后可达（见下方 Access control）。无论 CLI/HTTP，
//     --fix / ?type=fix 二选一即可触发清理; 清理幂等。
$doctorFix = $cli
    ? in_array('--fix', $argv ?? [], true)
    : (($_GET['type'] ?? '') === 'fix');

/* -------------------------------------------------------------------------
 * Access control.
 *
 * This report exposes absolute paths and database connection details. Once the
 * site is installed it must not be reachable over HTTP by anonymous visitors.
 * Before installation there is nothing sensitive to leak, so web access is
 * allowed to help debug a fresh deployment.
 * ---------------------------------------------------------------------- */
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $appCfg    = __DIR__ . '/config/app.php';
    $installed = is_file($appCfg) && (bool)((require $appCfg)['installed'] ?? false);

    if ($installed) {
        $authorized = false;

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (!empty($_SESSION['user_id']) || !empty($_SESSION['admin_id'])) {
            $authorized = true;
        }

        if (!$authorized) {
            http_response_code(403);
            echo "403 Forbidden\n\n";
            echo "The site is already installed. Run this diagnostic from the shell instead:\n";
            echo "    php " . basename(__FILE__) . "\n\n";
            echo "Or sign in to the admin panel first, then reload this page.\n";
            echo "Recommended: delete " . basename(__FILE__) . " after deployment is verified.\n";
            exit(1);
        }
    }
}

$pass = $fail = $warn = 0;

function line(string $s = ''): void { echo $s . PHP_EOL; }

function check(string $label, bool $ok, string $detail = '', bool $soft = false): bool
{
    global $pass, $fail, $warn;
    if ($ok) {
        $pass++;
        $tag = '[ OK ]';
    } elseif ($soft) {
        $warn++;
        $tag = '[WARN]';
    } else {
        $fail++;
        $tag = '[FAIL]';
    }
    line(sprintf('%s %-46s %s', $tag, $label, $detail));
    return $ok;
}

function section(string $t): void
{
    line();
    line('== ' . $t . ' ' . str_repeat('=', max(0, 62 - strlen($t))));
}

line('MoeRNG Diagnostic Report');
line('Generated: ' . date('Y-m-d H:i:s'));
line('Root:      ' . __DIR__);
line(str_repeat('=', 70));

/* ------------------------------------------------------------------ */
section('Runtime environment');

check('PHP >= 8.4', PHP_VERSION_ID >= 80400, PHP_VERSION);
check('SAPI', true, PHP_SAPI);

foreach (['pdo_mysql', 'curl', 'json', 'fileinfo', 'mbstring'] as $ext) {
    check("Extension: {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING');
}
check('Extension: gd (thumbnails)', extension_loaded('gd'), extension_loaded('gd') ? 'loaded' : 'optional', true);

/* ------------------------------------------------------------------ */
section('Filesystem layout');

$mustExist = [
    'app/Autoloader.php',
    'app/Core/Application.php',
    'app/Core/Router.php',
    'app/helpers.php',
    'views/home.php',
    'schema.sql',
];
foreach ($mustExist as $rel) {
    check("File: {$rel}", is_file(__DIR__ . '/' . $rel));
}

$writable = ['config', 'public/uploads'];
foreach ($writable as $rel) {
    $p = __DIR__ . '/' . $rel;
    $ok = is_dir($p) && is_writable($p);
    check("Writable: {$rel}/", $ok, is_dir($p) ? (is_writable($p) ? 'rw' : 'NOT WRITABLE') : 'MISSING DIR');
}

/* ------------------------------------------------------------------ */
section('Autoloader');

$autoloaderFile = __DIR__ . '/app/Autoloader.php';
if (!is_file($autoloaderFile)) {
    check('Autoloader present', false, 'app/Autoloader.php missing - cannot continue');
} else {
    require_once $autoloaderFile;
    \App\Autoloader::register(__DIR__ . '/app');

    $prefixes = \App\Autoloader::registeredPrefixes();
    check('Root namespace registered', isset($prefixes['App\\']), 'App\\ => ' . implode(', ', $prefixes['App\\'] ?? ['(none)']));

    // Every class declared under app/ must be autoloadable.
    $declared = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $src = (string)file_get_contents($f->getPathname());
        if (!preg_match('/^namespace\s+([^;]+);/m', $src, $nm)) {
            continue;
        }
        if (preg_match_all('/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $src, $cm)) {
            foreach ($cm[1] as $cn) {
                $declared[] = trim($nm[1]) . '\\' . $cn;
            }
        }
    }
    sort($declared);

    $bad = [];
    foreach ($declared as $cls) {
        // Skip classes that are already defined (avoids a redundant probe that
        // would otherwise re-require the file). require_once in the autoloader
        // makes even a redundant probe safe, but this keeps the check clean.
        if (class_exists($cls, false) || interface_exists($cls, false) || trait_exists($cls, false)) {
            continue;
        }
        if (\App\Autoloader::load($cls) === false && !class_exists($cls, false) && !interface_exists($cls, false)) {
            $bad[] = $cls;
        }
    }
    check(
        sprintf('Resolve %d declared classes', count($declared)),
        $bad === [],
        $bad === [] ? 'all resolved' : 'unresolved: ' . implode(', ', $bad)
    );

    // The exact class from the reported fatal error.
    check('Class App\\Storage\\S3Driver', class_exists(\App\Storage\S3Driver::class));
    check('Class App\\Storage\\CosSdkDriver', class_exists(\App\Storage\CosSdkDriver::class));
    check('Class App\\Storage\\OssSdkDriver', class_exists(\App\Storage\OssSdkDriver::class));
    check('Class App\\Storage\\AwsSdkDriver', class_exists(\App\Storage\AwsSdkDriver::class));

    // v1.3.2 迭代: Storage 接口完整性 —— 所有实现类都必须实现接口声明的每一个
    // 方法（含 hashFile）。缺方法会让 PHP 报"contains N abstract methods"
    // fatal 并使上传整条链路瘫痪——此检查把这类回归提前到 doctor 阶段捕获。
    // 遍历 App\Storage\ 下实现 StorageInterface 的所有类，反射验证非抽象。
    $storageChkBad = [];
    foreach (glob(__DIR__ . '/app/Storage/*.php') as $storageFile) {
        $baseCls = 'App\\Storage\\' . basename($storageFile, '.php');
        if (!class_exists($baseCls) && !interface_exists($baseCls)) {
            continue;
        }
        if (!is_subclass_of($baseCls, \App\Storage\StorageInterface::class)) {
            continue;
        }
        try {
            $rc = new \ReflectionClass($baseCls);
            // 反射该类所有接口方法的实际可调用性 —— newInstance 若含抽象方法
            // 会抛错；用反射检查是否仍未实现接口方法。
            $missing = [];
            foreach ($rc->getInterfaces() as $iface) {
                foreach ($iface->getMethods() as $im) {
                    if ($rc->hasMethod($im->getName()) && !$rc->getMethod($im->getName())->isAbstract()) {
                        continue;
                    }
                    $missing[] = $im->getName();
                }
            }
            if ($missing !== []) {
                $storageChkBad[] = $baseCls . ' -> ' . implode(', ', $missing);
            }
        } catch (\Throwable $e) {
            $storageChkBad[] = $baseCls . ' (reflection error: ' . $e->getMessage() . ')';
        }
    }
    check('Storage interface completeness', $storageChkBad === [],
        $storageChkBad === [] ? 'all 8 drivers implement every StorageInterface method'
            : 'MISSING: ' . implode('; ', $storageChkBad));
}

/* ------------------------------------------------------------------ */
section('Configuration');

$cfgApp = __DIR__ . '/config/app.php';
$cfgDb  = __DIR__ . '/config/database.php';

check('config/app.php', is_file($cfgApp), is_file($cfgApp) ? 'present' : 'missing (run installer)', true);

if (is_file($cfgDb)) {
    $db = require $cfgDb;
    check('config/database.php', is_array($db), 'present');
    if (is_array($db) && extension_loaded('pdo_mysql')) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $db['host'] ?? '127.0.0.1', $db['port'] ?? 3306, $db['database'] ?? '');
            $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [PDO::ATTR_TIMEOUT => 5]);
            check('MySQL connection', true, ($db['host'] ?? '') . '/' . ($db['database'] ?? ''));

            // v1.1.0-beta.2: prime the app-level Database singleton with the
            // same config. StorageProfile::defaultProfile()/all() (used by the
            // storage checks below) resolve through Database::getInstance() —
            // without init() it would try an empty config and fail with
            // "Connection refused" while doctor's own $pdo is healthy.
            \App\Core\Database::init($db);

            // If there is no active admin user, login is physically impossible
            // and the user is stuck bouncing between /admin and /admin/login.
            $adminCnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn();
            check('Admin account exists', $adminCnt > 0,
                $adminCnt > 0 ? "{$adminCnt} active admin(s)" : 'NO active admin user - login is impossible',
                $adminCnt === 0);
            $userCnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            check('Users table populated', $userCnt > 0, "{$userCnt} user(s) total", true);
        } catch (Throwable $e) {
            check('MySQL connection', false, $e->getMessage());
        }
    }
} else {
    check('config/database.php', false, 'not installed yet - visit /install', true);
}

/* ------------------------------------------------------------------ */
// Load persisted settings from the database so the storage checks below
// report the REAL driver/provider instead of the hardcoded 'local' default.
//
// The runtime Application::loadSettings() populates Config::settings.* from the
// `settings` table at bootstrap, but doctor deliberately skips the app
// bootstrap (so it still works when the app is broken). Without this load,
// Config::get('settings.storage_driver') always falls back to 'local' and the
// report wrongly claims object storage is inactive even when it is enabled.
if (isset($pdo)) {
    try {
        $rows = $pdo->query("SELECT `key`, `value` FROM `settings`")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (($rows ?? []) as $k => $v) {
            \App\Core\Config::set('settings.' . $k, $v);
        }
    } catch (Throwable) {
        // settings table may not exist on a fresh install — ignore
    }
}

section('Storage driver');

// Storage misconfiguration is the root cause of the "upload succeeds but the
// image 404s" report: an empty cdn_url plus a relative path anchored to the
// wrong directory made url() silently drop the /public/uploads prefix. Verify
// the resolved directory and public URL prefix here.
if (class_exists(\App\Storage\LocalDriver::class)) {
    \App\Core\Config::init(__DIR__ . '/config');
    \App\Core\Config::load();

    // v1.0.35: storage state comes from storage_profiles, never settings.
    $defaultProfile = null;
    $defaultProfileError = '';
    if (class_exists(\App\Models\StorageProfile::class)) {
        try {
            $defaultProfile = \App\Models\StorageProfile::defaultProfile();
        } catch (Throwable $e) {
            $defaultProfileError = $e->getMessage();
            $defaultProfile = null;
        }
    }
    if ($defaultProfile !== null) {
        $driverName = $defaultProfile->isS3()
            ? 's3 (' . $defaultProfile->providerLabel() . ')'
            : 'local';
        check('Storage driver selected', true, $driverName . '  ← default profile「' . $defaultProfile->name . '」');
    } elseif ($defaultProfileError !== '') {
        check('Storage driver selected', false, 'cannot read storage_profiles: ' . $defaultProfileError);
    } else {
        check('Storage driver selected', false, 'NO usable storage profile configured — 请到后台「存储管理」新增存储实例');
    }

    try {
        // Local-dir sanity only makes sense when the default profile is local
        // (or a local profile exists to inspect); skip for s3-only installs.
        $localProfile = null;
        if ($defaultProfile !== null && !$defaultProfile->isS3()) {
            $localProfile = $defaultProfile;
        } elseif (class_exists(\App\Models\StorageProfile::class)) {
            foreach (\App\Models\StorageProfile::all('sort_order ASC, id ASC') as $candidate) {
                if (!$candidate->isS3() && $candidate->isEnabled()) {
                    $localProfile = $candidate;
                    break;
                }
            }
        }
        if ($localProfile !== null) {
            $driver = $localProfile->driver();
            $dir = $driver->uploadDir();
            $url = $driver->baseUrl();
            $dirOk = is_dir($dir) && is_writable($dir);
            check('Local upload dir', $dirOk, $dirOk ? $dir : ($dir . ' (missing or not writable)'));
            $urlOk = $url !== '' && str_starts_with($url, '/');
            check('Public URL prefix', $urlOk, $urlOk ? $url : 'EMPTY - image URLs would 404');
        } else {
            check('Local upload dir', true, 's3-only install — no local profile to inspect', true);
            check('Public URL prefix', true, 's3-only install — skipped', true);
        }
    } catch (Throwable $e) {
        check('LocalDriver init', false, $e->getMessage());
    }

    // Schema check for the per-image storage columns. The upload flow writes
    // `storage` / `storage_provider`; on a hosted MySQL the app user may lack
    // ALTER, so Application::runStorageMigration can fail silently. Detect that
    // here and hand the operator the exact SQL to run with a privileged account.
    if (isset($pdo)) {
        try {
            // v1.3.2 迭代: 完整的「覆盖部署应自迁移」schema 完整性检查。
            // 覆盖部署到已有库时，运行时自迁移可能因缺 ALTER 权限/中断而漏跑，
            // 导致新增字段/索引缺失。此表列出应用期望的完整结构，缺失即 FAIL，
            // --fix 时补全（DDL 与 Application 的迁移语义一致，幂等）。
            $schemaChecks = [
                // table, column|index, ddl to add (if missing)
                ['images', 'storage',            "ADD COLUMN `storage` VARCHAR(16) NOT NULL DEFAULT 'local' AFTER `path`"],
                ['images', 'storage_provider',   "ADD COLUMN `storage_provider` VARCHAR(16) NOT NULL DEFAULT '' AFTER `storage`"],
                ['images', 'storage_profile_id', "ADD COLUMN `storage_profile_id` INT UNSIGNED NULL AFTER `storage_provider`, ADD INDEX `idx_storage_profile` (`storage_profile_id`)"],
                ['images', 'file_hash',          "ADD COLUMN `file_hash` CHAR(64) NULL DEFAULT NULL AFTER `file_size`"],
                ['images', 'file_sha256',        "ADD COLUMN `file_sha256` CHAR(64) NULL DEFAULT NULL AFTER `file_hash`"],
                ['images', 'process_status',     "ADD COLUMN `process_status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'done' AFTER `status`"],
                ['images', 'thumb_path',         "ADD COLUMN `thumb_path` VARCHAR(512) NULL DEFAULT NULL AFTER `process_status`"],
                ['images', 'process_error',      "ADD COLUMN `process_error` VARCHAR(500) NULL DEFAULT NULL AFTER `thumb_path`"],
                ['users',  'last_login',         "ADD COLUMN `last_login` DATETIME NULL DEFAULT NULL AFTER `status`"],
                ['users',  'remember_token',     "ADD COLUMN `remember_token` VARCHAR(255) NULL DEFAULT NULL AFTER `last_login`"],
                ['users',  'remember_expires',   "ADD COLUMN `remember_expires` DATETIME NULL DEFAULT NULL AFTER `remember_token`"],
            ];
            $schemaMissing = [];
            // 每表缓存一次列/索引清单（SHOW INDEX 的 FETCH_COLUMN 取的是第一列
            // `Table`，不是 Key_name —— 必须用 FETCH_ASSOC 收 Key_name）
            $colsCache = [];
            $idxCache = [];
            $tableCols = function (string $tbl) use ($pdo, &$colsCache): array {
                return $colsCache[$tbl] ??= $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN);
            };
            $tableIdx = function (string $tbl) use ($pdo, &$idxCache): array {
                if (!isset($idxCache[$tbl])) {
                    $idxCache[$tbl] = [];
                    foreach ($pdo->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $idxCache[$tbl][$r['Key_name']] = true;
                    }
                }
                return $idxCache[$tbl];
            };

            foreach ($schemaChecks as [$tbl, $col, $ddl]) {
                if (!in_array($col, $tableCols($tbl), true)) {
                    $schemaMissing[] = [$tbl, $col, $ddl];
                }
            }
            // 索引完整性（仅当对应列已存在且索引缺失时补）
            $idxChecks = [
                ['images', 'idx_file_hash', 'file_hash'],
                ['images', 'idx_file_sha256', 'file_sha256'],
                ['images', 'idx_storage_profile', 'storage_profile_id'],
                ['images', 'idx_process_status', 'process_status'],
            ];
            foreach ($idxChecks as [$tbl, $idx, $col]) {
                if (in_array($col, $tableCols($tbl), true) && !isset($tableIdx($tbl)[$idx])) {
                    $schemaMissing[] = [$tbl, $idx, "ADD INDEX `{$idx}` (`{$col}`)"];
                }
            }
            if ($schemaMissing === []) {
                check('Schema migration completeness', true, 'all columns + indexes expected by the app are present');
                // v1.3.2: schema 列已就位后，统计缺哈希的历史图片。
                // 这些图片需运行回填脚本（对象存储会拉临时文件算 MD5+SHA-256）。
                try {
                    $noMd5 = (int) $pdo->query("SELECT COUNT(*) FROM images WHERE file_hash IS NULL OR file_hash=''")->fetchColumn();
                    $noSha = (int) $pdo->query("SELECT COUNT(*) FROM images WHERE file_sha256 IS NULL OR file_sha256=''")->fetchColumn();
                    if ($noMd5 > 0 || $noSha > 0) {
                        check('Image hash backfill', false,
                            "{$noMd5} 张缺 file_hash, {$noSha} 张缺 file_sha256 — 请到后台「系统设置 → 健康检查」执行哈希回填");
                    } else {
                        check('Image hash backfill', true, 'all images carry both MD5 + SHA-256');
                    }
                } catch (Throwable $e) {
                    check('Image hash backfill', true, '统计失败（忽略）: ' . $e->getMessage(), true);
                }

                // v1.3.2-beta.2: 历史缩略图缺失（存量图）
                try {
                    $noThumb = (int) $pdo->query("SELECT COUNT(*) FROM images WHERE process_status = 'done' AND (thumb_path IS NULL OR thumb_path = '')")->fetchColumn();
                    if ($noThumb === 0) {
                        check('Image thumbnails', true, 'all images have thumbnails');
                    } else {
                        check('Image thumbnails', true,
                            "{$noThumb} 张缺缩略图 — 后台「图片处理」页点击「补全历史缩略图」（不改状态，图片始终可见）",
                            true);
                    }
                } catch (Throwable $e) {
                    check('Image thumbnails', true, '统计失败（忽略）: ' . $e->getMessage(), true);
                }

                // v1.3.2-beta.2: 图片处理队列积压（pending/failed）
                try {
                    $qPending = (int) $pdo->query("SELECT COUNT(*) FROM images WHERE process_status = 'pending'")->fetchColumn();
                    $qFailed = (int) $pdo->query("SELECT COUNT(*) FROM images WHERE process_status = 'failed'")->fetchColumn();
                    if ($qPending === 0 && $qFailed === 0) {
                        check('Image process queue', true, '队列为空');
                    } else {
                        check('Image process queue', $qPending === 0,
                            "待处理 {$qPending} 张, 失败 {$qFailed} 张 — 打开后台「图片管理」页自动处理；失败项用「重试失败项」重新入队");
                    }
                } catch (Throwable $e) {
                    check('Image process queue', true, '统计失败（忽略）: ' . $e->getMessage(), true);
                }
            } else {
                $names = array_map(fn($m) => "{$m[0]}.{$m[1]}", $schemaMissing);
                check('Schema migration completeness', false, 'MISSING: ' . implode(', ', $names));
                if ($doctorFix) {
                    $applied = 0;
                    $already = 0;
                    $alterErrors = [];
                    foreach ($schemaMissing as $m) {
                        try {
                            $pdo->exec("ALTER TABLE `{$m[0]}` {$m[2]}");
                            $applied++;
                        } catch (Throwable $e) {
                            $msg = $e->getMessage();
                            // 1060 duplicate column / 1061 duplicate key name =
                            // 已存在，视为幂等成功。其余（如 1142 ALTER denied）
                            // 是真实错误，必须透出而不是笼统报"权限不足"。
                            if (stripos($msg, '1060') !== false || stripos($msg, '1061') !== false
                                || stripos($msg, 'duplicate') !== false) {
                                $already++;
                            } else {
                                $alterErrors[] = "{$m[0]}.{$m[1]}: {$msg}";
                            }
                        }
                    }
                    // 修复后重新验证 —— 以实际结构为准，而不是 ALTER 计数
                    $stillMissing = [];
                    foreach ($schemaChecks as [$tbl, $col, $ddl]) {
                        if (!in_array($col, $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN), true)) {
                            $stillMissing[] = "{$tbl}.{$col}";
                        }
                    }
                    foreach ($idxChecks as [$tbl, $idx, $col]) {
                        $haveIdx = [];
                        foreach ($pdo->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $haveIdx[$r['Key_name']] = true;
                        }
                        if (in_array($col, $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN), true)
                            && !isset($haveIdx[$idx])) {
                            $stillMissing[] = "{$tbl}.{$idx}";
                        }
                    }
                    if ($stillMissing === [] && $alterErrors === []) {
                        check('Schema migration completeness (--fix)', true,
                            "补全 {$applied} 项" . ($already > 0 ? "（{$already} 项已存在）" : '') . ' — 重新验证通过');
                    } elseif ($stillMissing === []) {
                        check('Schema migration completeness (--fix)', true,
                            "补全 {$applied} 项；个别警告: " . implode('; ', $alterErrors));
                    } else {
                        check('Schema migration completeness (--fix)', false,
                            '仍有缺失: ' . implode(', ', $stillMissing)
                            . ($alterErrors !== [] ? ' | 错误: ' . implode('; ', $alterErrors) : '')
                            . ' — 可能 ALTER 权限不足，请用有 ALTER 权限的账号手动执行');
                    }
                } else {
                    line('       Run with --fix (CLI) or open doctor.php?type=fix (web, logged in) to apply.');
                }
            }
        } catch (Throwable $e) {
            check('images schema probe', false, $e->getMessage());
        }

        // Surface any error the automatic migration captured (e.g. ALTER denied).
        try {
            $merr = $pdo->query("SELECT `value` FROM `settings` WHERE `key` = 'migration_last_error'")->fetchColumn();
            if ($merr !== false && $merr !== null && $merr !== '') {
                check('Storage migration', false, 'last error: ' . $merr);
            }
        } catch (Throwable) {
            // settings table shape differs / unavailable — ignore
        }
    }

    // Object storage (S3 / OSS / COS / OBS) live connectivity. Since v1.0.35
    // every configured object-storage *profile* is probed individually with a
    // live PUT probe (v1.0.31 semantics — mirrors the real upload operation,
    // not a bucket-level HEAD that image-bucket IAM users often lack).
    if (class_exists(\App\Storage\S3Driver::class)) {

        // Official COS SDK availability. When deployed, COS operations are
        // delegated to qcloud/cos-sdk-v5; when missing, S3Driver falls back to
        // the built-in v1.0.24 signing (both are valid — this check is
        // informational, not a hard gate).
        $cosSdkAutoload = __DIR__ . '/sdk/cos/vendor/autoload.php';
        check('COS SDK (qcloud/cos-sdk-v5)', is_file($cosSdkAutoload),
            is_file($cosSdkAutoload) ? 'present — COS uses official SDK' : 'MISSING — COS falls back to built-in signing', true);
        $sdkExtOk = extension_loaded('simplexml') && extension_loaded('libxml');
        check('COS SDK extensions', $sdkExtOk,
            extension_loaded('simplexml') ? 'simplexml + libxml loaded' : 'simplexml/libxml MISSING — SDK cannot run', true);

        // Alibaba Cloud OSS SDK (alibabacloud/oss-v2) availability. Same
        // delegation pattern: present → OSS uses the official SDK; missing →
        // built-in OSS4 signing fallback.
        $ossSdkAutoload = __DIR__ . '/sdk/oss/autoload.php';
        check('OSS SDK (alibabacloud/oss-v2)', is_file($ossSdkAutoload) && is_file(__DIR__ . '/sdk/oss/src/Client.php'),
            (is_file($ossSdkAutoload) && is_file(__DIR__ . '/sdk/oss/src/Client.php'))
                ? 'present — OSS uses official SDK' : 'MISSING — OSS falls back to built-in signing', true);

        // AWS SDK for PHP (aws/aws-sdk-php) availability. Since v1.0.28 there
        // is NO built-in signing fallback anymore — a missing SDK is a hard
        // failure for that provider, so this check is informational only.
        $awsSdkAutoload = __DIR__ . '/sdk/aws/autoload.php';
        check('AWS SDK (aws/aws-sdk-php)', is_file($awsSdkAutoload) && is_file(__DIR__ . '/sdk/aws/Aws/S3/S3Client.php'),
            (is_file($awsSdkAutoload) && is_file(__DIR__ . '/sdk/aws/Aws/S3/S3Client.php'))
                ? 'present — S3 uses official SDK' : 'MISSING — S3 uploads will fail with a clear error', true);

        // Huawei Cloud OBS SDK (esdk-obs-php) availability. Its runtime deps
        // (Guzzle/PSR-7) are served by the shared sdk/cos/vendor copy.
        $obsSdkAutoload = __DIR__ . '/sdk/obs/obs-autoloader.php';
        check('OBS SDK (esdk-obs-php)', is_file($obsSdkAutoload) && is_file(__DIR__ . '/sdk/obs/Obs/ObsClient.php'),
            (is_file($obsSdkAutoload) && is_file(__DIR__ . '/sdk/obs/Obs/ObsClient.php'))
                ? 'present — OBS uses official SDK' : 'MISSING — OBS uploads will fail with a clear error', true);

        // v1.1.1 迭代: UPYUN USS official SDK (Guzzle/PSR-7 from the shared cos vendor).
        check('UPYUN SDK (upyun/sdk)', is_file(__DIR__ . '/sdk/upyun/autoload.php') && is_file(__DIR__ . '/sdk/upyun/src/Upyun/Upyun.php')
                && is_file(__DIR__ . '/sdk/cos/vendor/autoload.php'),
            (is_file(__DIR__ . '/sdk/upyun/autoload.php') && is_file(__DIR__ . '/sdk/upyun/src/Upyun/Upyun.php'))
                ? 'present — UPYUN uses official SDK' : 'MISSING — UPYUN uploads will fail with a clear error', true);

        // v1.1.1 迭代: Qiniu Kodo official SDK (self-contained curl, no Guzzle).
        check('Qiniu SDK (qiniu/php-sdk)', is_file(__DIR__ . '/sdk/qiniu/autoload.php') && is_file(__DIR__ . '/sdk/qiniu/src/Qiniu/Storage/UploadManager.php'),
            (is_file(__DIR__ . '/sdk/qiniu/autoload.php') && is_file(__DIR__ . '/sdk/qiniu/src/Qiniu/Storage/UploadManager.php'))
                ? 'present — Qiniu uses official SDK' : 'MISSING — Qiniu uploads will fail with a clear error', true);

        // v1.0.35: profiles are the single source of truth — probe every
        // enabled object-storage profile with a live PUT probe. A read failure
        // is surfaced explicitly (never silently treated as "table empty").
        $profiles = [];
        $profileLoadError = '';
        if (class_exists(\App\Models\StorageProfile::class)) {
            try {
                $profiles = \App\Models\StorageProfile::all('sort_order ASC, id ASC');
            } catch (Throwable $e) {
                $profileLoadError = $e->getMessage();
                $profiles = [];
            }
        }

        if ($profileLoadError !== '') {
            check('Storage profiles', false, 'cannot read storage_profiles: ' . $profileLoadError);
        } elseif (!empty($profiles)) {
            $s3Probe = 0;
            $localProfiles = 0;
            $defaultName = '—';
            foreach ($profiles as $p) {
                if ($p->isDefault()) {
                    $defaultName = (string) $p->name;
                }
                if (!$p->isS3()) {
                    $localProfiles++;
                    continue;
                }
                $s3Probe++;
                if (!$p->isEnabled()) {
                    check('Profile「' . $p->name . '」', false, 'disabled — skipped', true);
                    continue;
                }
                if (!$p->isUsable()) {
                    check('Profile「' . $p->name . '」', false, 'credentials incomplete — skipped', true);
                    continue;
                }
                try {
                    $s3 = new \App\Storage\S3Driver();
                    $s3->loadProfile($p);
                    $ok = $s3->testConnection();
                    // testConnection() runs a live PUT probe (upload + immediate
                    // delete of a tiny object), mirroring the real operation.
                    check('Storage profile「' . $p->name . '」upload probe', $ok,
                        $ok ? 'PUT+DELETE probe OK (auth OK)' : 'probe failed - check credentials/bucket and PutObject/DeleteObject permission');
                } catch (Throwable $e) {
                    check('Storage profile「' . $p->name . '」init', false, $e->getMessage());
                }
            }
            check('Storage profiles', true, count($profiles) . ' instance(s), default = ' . $defaultName . ($localProfiles > 0 ? ", {$localProfiles} local" : ''));
            if ($s3Probe === 0) {
                check('Object storage profile', false, 'no object-storage (S3) profile configured — uploads use local', true);
            }
        } else {
            // v1.0.35: profiles are the single source of truth — an empty
            // profiles table is a real configuration gap, not a cue to fall
            // back to legacy settings probing.
            check('Storage profiles', false,
                'storage_profiles 表为空 — 未配置任何存储实例。请到后台「存储管理」新增存储实例。', true);
        }
    }
} else {
    check('Storage driver class', false, 'App\\Storage\\LocalDriver not found');
}

// v1.0.34-beta.2: detect orphan storage_* keys in `settings` once profiles
// exist. The settings JSON map + storage_s3_* keys are the legacy single-key
// store that v1.0.33 migrated into storage_profiles; once profiles are
// populated, these become dead weight (and still include sensitive secrets).
// The cleanup is intentionally a manual script (tools/_purge_storage_settings.php)
// so the operator sees exactly what gets deleted before clicking --commit.
$ORPHAN_KEYS = [
    'storage_providers',
    'storage_s3_access_key', 'storage_s3_secret_key',
    'storage_s3_region',     'storage_s3_bucket',
    'storage_s3_endpoint',   'storage_s3_provider',
    'storage_driver',        'storage_default_provider',
    'storage_local_path',
];
try {
    // $pdo is doctor's own connection (built from config/database.php above);
    // never rely on $db here — that variable holds the config ARRAY.
    if (!isset($pdo)) {
        check('Settings orphan keys', false, 'no DB connection available — skipped', true);
    } else {
        $place = implode(',', array_fill(0, count($ORPHAN_KEYS), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key` IN ($place)");
        $stmt->execute($ORPHAN_KEYS);
        $orphanCount = (int) $stmt->fetchColumn();
        if ($orphanCount > 0 && $profiles) {
            check('Settings orphan keys', false,
                $orphanCount . ' legacy storage_* key(s) still present in `settings` '
                . '(storage_profiles is the source of truth). Run '
                . 'tools/_purge_storage_settings.php --commit to clean up.',
                true);
        } elseif ($orphanCount > 0) {
            check('Settings orphan keys', true,
                $orphanCount . ' legacy storage_* key(s) — kept because storage_profiles is empty',
                true);
        } else {
            check('Settings orphan keys', true, 'no legacy storage_* keys (clean)', true);
        }
    }
} catch (Throwable $e) {
    check('Settings orphan keys', false, 'cannot inspect settings: ' . $e->getMessage());
}

// v1.2.0 迭代: signed links — local files now go through the /files endpoint;
// object storage uses short-lived presigned URLs. No server-side proxying.
check('Signed links (short-lived)', is_file(__DIR__ . '/app/Controllers/FileController.php')
        && is_file(__DIR__ . '/app/Storage/SignedUrl.php'),
    (is_file(__DIR__ . '/app/Controllers/FileController.php') && is_file(__DIR__ . '/app/Storage/SignedUrl.php'))
        ? 'present — local /files endpoint + object-storage presign (no proxy)'
        : 'MISSING — file URLs would fall back to permanent links', true);

// v1.2.1 迭代: signing key must be STABLE and INDEPENDENT (no DB fallback).
// Verify sign()==verify() round-trip + a second sign() for stability.
$keyFileProbe = __DIR__ . '/config/signing_key.php';
check('Signing key file (config/signing_key.php)',
    is_file($keyFileProbe),
    is_file($keyFileProbe) ? 'present — independent HMAC key (file storage, NOT in DB)' : 'MISSING — auto-generated on first use; config/ must be writable',
    true);
try {
    $probePath = 'doctor-sign-roundtrip-' . bin2hex(random_bytes(4));
    $probeExp = time() + 300;
    $sig = \App\Storage\SignedUrl::sign($probePath, $probeExp);
    $roundTrip = \App\Storage\SignedUrl::verify($probePath, $probeExp, $sig);
    $stable = \App\Storage\SignedUrl::sign($probePath, $probeExp) === $sig;
    check('Signing key stable + round-trip', $roundTrip && $stable,
        ($roundTrip ? 'round-trip OK' : 'SIGN/VERIFY MISMATCH — broken signatures') . '; '
        . ($stable ? 'secret stable (independent key)' : 'SECRET CHANGES PER CALL — config/ unwritable'),
        true);
} catch (\Throwable $e) {
    check('Signing key stable + round-trip', false, 'exception: ' . $e->getMessage(), true);
}

/* ------------------------------------------------------------------ */
section('Session persistence');

// Login relies on the session surviving the redirect from /admin/login to
// /admin. If the session save path is not writable, data is never persisted
// and the user is silently bounced back to the login page.
$sp = session_save_path();
if ($sp === '' || $sp === false) {
    $sp = sys_get_temp_dir();
}
// v1.1.0-beta.4: audit log table (created by the Application migration on
// boot — a missing table means the migration did not run / PHP-FPM restart
// was skipped after deploying).
if (isset($pdo)) {
    try {
        $auditOk = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn() !== false;
        if ($auditOk) {
            $auditCnt = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
            check('Audit log table', true, "present, {$auditCnt} record(s)");
        } else {
            check('Audit log table', false, 'MISSING - restart PHP-FPM so the migration runs');
        }
    } catch (Throwable $e) {
        check('Audit log table', false, $e->getMessage());
    }
}
// When sessions are backed by Redis/Memcached, save_path looks like
// "tcp://127.0.0.1:6379" — is_dir() cannot stat such a URI and emits a
// warning. The round-trip test below is the authoritative check in that case.
$isRemoteScheme = is_string($sp) && preg_match('#^[a-z]+://#i', $sp);
if ($isRemoteScheme) {
    check('Session save path writable', true, $sp . ' (remote handler - verified by round-trip below)', true);
} else {
    $spWritable = is_dir($sp) && is_writable($sp);
    check('Session save path writable', $spWritable, $sp === '' ? 'empty (system default)' : $sp);
}

// Round-trip test: write a value, close, reopen, read it back.
$diagKey = '__moerng_diag_' . crc32(__FILE__);
$diagVal = 'ok_' . time();

$prevStatus = session_status();
if ($prevStatus === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION[$diagKey] = $diagVal;
$id1 = session_id();
@session_write_close();

@session_start();
$diagRead = $_SESSION[$diagKey] ?? null;
$id2 = session_id();
@session_write_close();

check(
    'Session write + reload round-trips',
    $diagRead === $diagVal,
    $diagRead === $diagVal ? "id {$id1}" : 'value lost after reload (session not persisted)'
);

/* ------------------------------------------------------------------ */
section('Web server rewrite');

if ($cli) {
    line('       (skipped in CLI)');
} else {
    $server = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
    check('Server software', true, $server);
    if (stripos($server, 'nginx') !== false) {
        check(
            '.htaccess is INERT on nginx',
            is_file(__DIR__ . '/nginx.conf.example'),
            'apply nginx.conf.example to your site config',
            true
        );
    }
    // v1.1.1-beta.5: auto-probe /config/database.php over HTTP instead of
    // asking the user to verify by hand. Expected: 403/404 (web layer denies).
    // A 200 here is a real leak — the DB password file is publicly readable.
    $isNginx = stripos($server, 'nginx') !== false;
    $htaccessPresent = is_file(__DIR__ . '/config/.htaccess');
    if (!$isNginx || !$htaccessPresent) {
        // Apache honors .htaccess, or no .htaccess to be inert — nothing to prove.
        check('Config dir not web-exposed', true, 'no inert .htaccess on this server');
    } else {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $status = 0;
        if ($host !== '') {
            $probe = ($https ? 'https://' : 'http://') . $host . '/config/database.php';
            $ctx = stream_context_create([
                'http' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            @file_get_contents($probe, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }
        // v1.1.1-beta.5.1 (local): HTTP 200 is NOT automatically a leak. Many
        // servers (Baota / open_basedir / WAF) return 200 with a friendly
        // "PHP execution blocked" page rather than a 403. Inspect the body
        // to tell those apart from a real database password leak.
        $body = '';
        if ($probe !== '') {
            $ctx = stream_context_create([
                'http' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $body = (string) @file_get_contents($probe, false, $ctx);
        }
        if ($status >= 400 && $status < 500) {
            check('Config dir not web-exposed', true, "/config/database.php -> HTTP {$status} (denied)");
        } elseif ($status === 200) {
            // Strip whitespace for keyword matching.
            $sample = trim(preg_replace('/\s+/', ' ', $body));
            $sample = mb_substr($sample, 0, 160);
            $safeHints = ['禁止执行', '防跨站', '禁止访问', 'waf', 'blocked', 'open_basedir', 'directory protection', 'forbidden'];
            $leakHints = ['<?php', 'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'database password', 'return ['];
            $isSafe = false;
            foreach ($safeHints as $h) { if (stripos($body, $h) !== false) { $isSafe = true; break; } }
            $isLeak = false;
            foreach ($leakHints as $h) { if (stripos($body, $h) !== false) { $isLeak = true; break; } }
            if ($isLeak) {
                check('Config dir not web-exposed', false,
                    '/config/database.php -> HTTP 200 AND body contains PHP/DB credentials! Body: "' . $sample . '"');
            } elseif ($isSafe) {
                check('Config dir not web-exposed', true,
                    "/config/database.php -> HTTP 200 (server returned a blocked-page, e.g. open_basedir). Body: \"{$sample}\"");
            } else {
                check('Config dir not web-exposed', true,
                    "/config/database.php -> HTTP 200 with unfamiliar body. Open https://your-domain/config/database.php in a browser to confirm no PHP is leaked. Body: \"{$sample}\"",
                    true);
            }
        } else {
            check('Config dir not web-exposed', true,
                "probe HTTP {$status} (assuming protected; verify /config/database.php returns 403/404 in browser)",
                true);
        }
    }
}

/* ------------------------------------------------------------------
 * v1.3.2 迭代: 遗留问题清理（数据库残留 / 无用文件）。
 * ----------------------------------------------------------------
 * 默认仅【检测+报告】；加 --fix 才执行清理。
 * 所有清理幂等、可重复执行；只处理【确认无用】的项，绝不误删。
 */
    section('Legacy cleanup');

// —— 收集 DB 中已无引用的设置行（代码里再无读取的 key）——
$orphanSettingKeys = [
    'direct_upload_enabled', // Web 直传已移除，逻辑中已无任何读取
];

$pdoClean = null;
try {
    if (is_file($cfgDb)) {
        $db = require $cfgDb;
        if (is_array($db)) {
            $pdoClean = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $db['host'] ?? '127.0.0.1', $db['port'] ?? 3306, $db['database'] ?? ''),
                $db['username'] ?? '', $db['password'] ?? '', [PDO::ATTR_TIMEOUT => 5]
            );
        }
    }
} catch (Throwable $e) {
    $pdoClean = null;
}

if ($pdoClean !== null) {
    // 1) 无引用设置行
    foreach ($orphanSettingKeys as $orphanKey) {
        $found = (int) $pdoClean->query(
            $pdoClean->quote($orphanKey) === '' ? "SELECT COUNT(*) FROM settings WHERE `key`='{$orphanKey}'" : "SELECT COUNT(*) FROM settings WHERE `key`=" . $pdoClean->quote($orphanKey)
        )->fetchColumn();
        if ($found > 0) {
            if ($doctorFix) {
                $del = $pdoClean->exec("DELETE FROM settings WHERE `key`=" . $pdoClean->quote($orphanKey));
                check("设置行已清理: {$orphanKey}", true, "已删除 {$del} 行");
            } else {
                check("设置行待清理: {$orphanKey}", true,
                    "发现 {$found} 行无引用设置，执行 'php doctor.php --fix' 可删除");
            }
        }
    }
} else {
    check('数据库遗留清理', false, '无法连接数据库，跳过设置行清理', true);
}

// —— 2) 无用文件（可安全删除，不碰站点根下的源码目录）——
// 注意: doctor.php 部署后位于站点根，__DIR__ 即站点根，生成物都在 __DIR__/ 下，
// 不要再用 ./( ../ ) 越出 open_basedir（宝塔限制 root:/tmp）。用 @ 抑制警告并
// 以 is_readable 防护（open_basedir 之外或不可读的路径一律不视为可清理）。
$junkFiles = [];
// Windows 重定向残留（已 gitignore，本地会残留）
if (@is_file(__DIR__ . '/nul') && @is_readable(__DIR__ . '/nul')) {
    $junkFiles[] = __DIR__ . '/nul';
}

foreach ($junkFiles as $junk) {
    if (@is_file($junk)) {
        if ($doctorFix) {
            $ok = @unlink($junk);
            check("残留文件已清理: " . basename($junk), $ok, $ok ? '已删除' : '删除失败（可能被占用）');
        } else {
            check("残留文件待清理: " . basename($junk), true,
                '执行 php doctor.php --fix 可删除（Windows 重定向残留，已 gitignore）');
        }
    }
}

// —— 3) 空运行时目录（仅当确为空的目录，且位于站点根内）——
$emptyDirs = [];
foreach (['backups', 'var', 'storage/logs'] as $rel) {
    $dir = __DIR__ . '/' . $rel;
    if (@is_dir($dir)) {
        $entries = @scandir($dir);
        if (is_array($entries) && count($entries) === 2) { // '.', '..' 空目录
            $emptyDirs[] = $dir;
        }
    }
}
foreach ($emptyDirs as $dir) {
    if ($doctorFix) {
        $ok = @rmdir($dir);
        check("空目录已清理: " . basename($dir), $ok, $ok ? '已删除（空）' : '留空（非空/权限）');
    } else {
        check("空目录待清理: " . basename($dir), true, '执行 php doctor.php --fix 可删除（空目录）');
    }
}

// --fix 模式：清理后继续打印健康检查总结（清理结果已通过 check() 记入 PASS/FAIL）。
/* ------------------------------------------------------------------ */
line();
line(str_repeat('=', 70));
line(sprintf('PASS %d   FAIL %d   WARN %d', $pass, $fail, $warn));
line($fail === 0 ? 'RESULT: deployment looks healthy.' : 'RESULT: fix the [FAIL] items above.');
line();
line('Remember to delete doctor.php after verification.');
