<?php
declare(strict_types=1);

/** 独立自检工具：不依赖应用 bootstrap，应用故障时仍可运行。 */

define('MOERNG_DEBUG_AUTOLOAD', true);

$cli = PHP_SAPI === 'cli';

// 默认仅检测；--fix（CLI）或 ?type=fix（HTTP）才执行清理，且清理幂等。
$doctorFix = $cli
    ? in_array('--fix', $argv ?? [], true)
    : (($_GET['type'] ?? '') === 'fix');

// 已安装站点必须登录后才能访问（报告含绝对路径与数据库信息）。
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $appCfg    = __DIR__ . '/config/app.php';
    $installed = is_file($appCfg) && (bool)((require $appCfg)['installed'] ?? false);

    if ($installed) {
        $authorized = false;

        if (session_status() === PHP_SESSION_NONE) {
            // 本文件绕开 bootstrap，这里补齐 Session 的 cookie 加固参数。
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
                    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
            ]);
            ini_set('session.use_strict_mode', '1');
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

section('Runtime environment');

check('PHP >= 8.4', PHP_VERSION_ID >= 80400, PHP_VERSION);
check('SAPI', true, PHP_SAPI);

foreach (['pdo_mysql', 'curl', 'json', 'fileinfo', 'mbstring'] as $ext) {
    check("Extension: {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING');
}
check('Extension: gd (thumbnails)', extension_loaded('gd'), extension_loaded('gd') ? 'loaded' : 'optional', true);

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

// 目录未创建时以父目录可写为通过；此处刻意用字面量（自动加载器尚未注册）。
$writable = [
    'config' => 'config',
    'storage/uploads (媒体根)' => 'storage/uploads',
    'storage/incoming (上传中转)' => 'storage/incoming',
    'public/uploads (品牌 logo)' => 'public/uploads',
];
foreach ($writable as $label => $rel) {
    $p = __DIR__ . '/' . $rel;
    if (is_dir($p)) {
        $ok = is_writable($p);
        $detail = $ok ? 'rw' : 'NOT WRITABLE';
    } else {
        $ok = is_writable(dirname($p));
        $detail = $ok ? 'not created yet (parent rw)' : 'MISSING and parent not writable';
    }
    check("Writable: {$label}/", $ok, $detail);
}

section('Autoloader');
$autoloaderFile = __DIR__ . '/app/Autoloader.php';
if (!is_file($autoloaderFile)) {
    check('Autoloader present', false, 'app/Autoloader.php missing - cannot continue');
} else {
    require_once $autoloaderFile;
    \App\Autoloader::register(__DIR__ . '/app');

    $prefixes = \App\Autoloader::registeredPrefixes();
    check('Root namespace registered', isset($prefixes['App\\']), 'App\\ => ' . implode(', ', $prefixes['App\\'] ?? ['(none)']));

    // 校验字面量与 LocalDriver 常量一致，漂移会让报告失真。
    if (class_exists(\App\Storage\LocalDriver::class)) {
        $relDir = \App\Storage\LocalDriver::defaultRelDir();
        check('Storage layout constant', $relDir === 'storage/uploads',
            'LocalDriver::defaultRelDir() = ' . $relDir
            . ($relDir === 'storage/uploads' ? ' [OK]' : ' ← 与 doctor 预期不一致，请核对'));
    }

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

    check('Class App\\Storage\\S3Driver', class_exists(\App\Storage\S3Driver::class));
    check('Class App\\Storage\\CosSdkDriver', class_exists(\App\Storage\CosSdkDriver::class));
    check('Class App\\Storage\\OssSdkDriver', class_exists(\App\Storage\OssSdkDriver::class));
    check('Class App\\Storage\\AwsSdkDriver', class_exists(\App\Storage\AwsSdkDriver::class));

    // 反射校验所有 StorageInterface 实现类，缺方法会让上传链路 fatal。
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

            // 用同一配置初始化 Database 单例，后面存储检查才走对连接。
            \App\Core\Database::init($db);

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

// doctor 跳过 bootstrap，这里手动载入 settings，否则存储报告会错报 local。
if (isset($pdo)) {
    try {
        $rows = $pdo->query("SELECT `key`, `value` FROM `settings`")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (($rows ?? []) as $k => $v) {
            \App\Core\Config::set('settings.' . $k, $v);
        }
    } catch (Throwable) {
    }
}

section('Storage driver');

if (class_exists(\App\Storage\LocalDriver::class)) {
    \App\Core\Config::init(__DIR__ . '/config');
    \App\Core\Config::load();

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

            $mode = method_exists($driver, 'servingMode') ? $driver->servingMode() : 'signed';
            check('Local serving mode', true, $mode === 'cdn'
                ? 'cdn — ' . $driver->baseUrl() . '（CDN 需回源到 ' . $dir . '）'
                : 'signed — /files?p=…&e=…&s=…（短时签名，无固定直链）');

            $docRoot = (string) (realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
            $realDir = (string) (realpath($dir) ?: '');
            $inWebRoot = $docRoot !== '' && $realDir !== ''
                && str_starts_with($realDir . DIRECTORY_SEPARATOR, $docRoot . DIRECTORY_SEPARATOR);
            check('媒体根不在 web 根之内', !$inWebRoot, $inWebRoot
                ? $dir . ' ← 位于 web 根 ' . $docRoot . ' 之内：文件可被静态直读，签名机制形同虚设，建议迁到 storage/uploads'
                : ($realDir !== '' ? $realDir . '（web 根之外 [OK]）' : $dir . '（目录尚未创建）'), true);

            $urlOk = $url !== '' && str_starts_with($url, '/');
            check('Public URL prefix', $urlOk, $urlOk ? $url : 'EMPTY - image URLs would 404');
        } else {
            check('Local upload dir', true, 's3-only install — no local profile to inspect', true);
            check('Local serving mode', true, 's3-only install — skipped', true);
            check('媒体根不在 web 根之内', true, 's3-only install — skipped', true);
            check('Public URL prefix', true, 's3-only install — skipped', true);
        }

        // 预签名 URL 每次渲染都变，浏览器缓存必 miss；绑定 CDN 后地址才稳定。
        if ($defaultProfile !== null && $defaultProfile->isS3()) {
            $s3cfg = $defaultProfile->config();
            $domain = trim((string) ($s3cfg['cdn'] ?? ''));
            check('云端图片 URL 稳定性', $domain !== '', $domain !== ''
                ? 'stable — ' . $domain . '（浏览器/CDN 可缓存）'
                : '预签名直链每次渲染都会变 → 浏览器缓存必然 miss，每次浏览都重新下载；'
                  . '建议给该存储实例绑定 CDN / 自定义源站域名（存储管理 → 该实例的「CDN 域名」）', true);
        } else if ($localProfile !== null) {
            check('本地图片 URL 稳定性', true, '稳定 —— 签名按 60s 窗口对齐，同一窗口内 URL 完全一致，可命中浏览器缓存', true);
        }

    } catch (Throwable $e) {
        check('LocalDriver init', false, $e->getMessage());
    }

    if (isset($pdo)) {
        try {
            $expect = \App\Core\Application::schemaExpectations();
            $schemaMissing = [];
            // SHOW INDEX 必须取 Key_name（FETCH_COLUMN 拿到的是 Table 列）。
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

            // 先列后索引：--fix 按 $schemaMissing 顺序执行 ALTER，索引依赖列已存在。
            foreach ($expect['columns'] as $tbl => $cols) {
                foreach ($cols as $col => $def) {
                    if (!in_array($col, $tableCols($tbl), true)) {
                        $schemaMissing[] = [$tbl, $col, "ADD COLUMN `{$col}` {$def}"];
                    }
                }
            }
            foreach ($expect['indexes'] as $tbl => $idxs) {
                foreach ($idxs as $idx => $ddl) {
                    if (!isset($tableIdx($tbl)[$idx])) {
                        $schemaMissing[] = [$tbl, $idx, $ddl];
                    }
                }
            }
            // 内容哈希索引表：缺了它上传去重退化为 MD5 兜底（运行时迁移会自动建）
            $chTable = $pdo->query("SHOW TABLES LIKE 'content_hashes'")->fetchColumn() !== false;
            check(
                'Content hash index table',
                $chTable,
                $chTable ? 'content_hashes present' : 'content_hashes missing — 访问一次站点由运行时迁移自动创建',
                true
            );

            if ($schemaMissing === []) {
                check('Schema migration completeness', true, 'all columns + indexes expected by the app are present');
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

                try {
                    $noThumb = (int) $pdo->query("SELECT COUNT(*) FROM images WHERE process_status = 'done' AND " . \App\Models\Image::thumbMetaPendingSql())->fetchColumn();
                    if ($noThumb === 0) {
                        check('Image thumbnails', true, 'all images have multi-size thumbnails (sm/md/lg)');
                    } else {
                        check('Image thumbnails', true,
                            "{$noThumb} 张缺多尺寸缩略图 — 后台「图片处理」页点击「补全历史缩略图」（不改状态，图片始终可见）",
                            true);
                    }
                } catch (Throwable $e) {
                    check('Image thumbnails', true, '统计失败（忽略）: ' . $e->getMessage(), true);
                }

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
                            // 1060/1061 视为已存在；其余错误必须透出，不能笼统报权限不足。
                            if (stripos($msg, '1060') !== false || stripos($msg, '1061') !== false
                                || stripos($msg, 'duplicate') !== false) {
                                $already++;
                            } else {
                                $alterErrors[] = "{$m[0]}.{$m[1]}: {$msg}";
                            }
                        }
                    }
                    $stillMissing = [];
                    foreach ($expect['columns'] as $tbl => $cols) {
                        $have = $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN);
                        foreach (array_keys($cols) as $col) {
                            if (!in_array($col, $have, true)) {
                                $stillMissing[] = "{$tbl}.{$col}";
                            }
                        }
                    }
                    foreach ($expect['indexes'] as $tbl => $idxs) {
                        $haveIdx = [];
                        foreach ($pdo->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $haveIdx[$r['Key_name']] = true;
                        }
                        foreach (array_keys($idxs) as $idx) {
                            if (!isset($haveIdx[$idx])) {
                                $stillMissing[] = "{$tbl}.{$idx}";
                            }
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

        try {
            $noProfile = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE `storage_profile_id` IS NULL"
            )->fetchColumn();
            $urlLeft = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE `url` IS NOT NULL AND `url` <> ''"
            )->fetchColumn();
            if ($noProfile === 0 && $urlLeft === 0) {
                check('Legacy data migration', true, '无历史兼容数据残留');
            } else {
                check('Legacy data migration', false,
                    "{$noProfile} 张缺存储实例归属、{$urlLeft} 行残留 url —— "
                    . '运行 php src/cli/migrate-legacy-refs.php --apply'
                    . '（迁移前这些图片的读取会明确报错）');
            }
        } catch (Throwable $e) {
            check('Legacy data migration', true, '检测失败（忽略）: ' . $e->getMessage(), true);
        }

        try {
            $merr = $pdo->query("SELECT `value` FROM `settings` WHERE `key` = 'migration_last_error'")->fetchColumn();
            if ($merr !== false && $merr !== null && $merr !== '') {
                check('Storage migration', false, 'last error: ' . $merr);
            }
        } catch (Throwable) {
        }
    }

    if (class_exists(\App\Storage\S3Driver::class)) {

        $cosSdkAutoload = __DIR__ . '/sdk/cos/vendor/autoload.php';
        check('COS SDK (qcloud/cos-sdk-v5)', is_file($cosSdkAutoload),
            is_file($cosSdkAutoload) ? 'present — COS uses official SDK' : 'MISSING — COS falls back to built-in signing', true);
        $sdkExtOk = extension_loaded('simplexml') && extension_loaded('libxml');
        check('COS SDK extensions', $sdkExtOk,
            extension_loaded('simplexml') ? 'simplexml + libxml loaded' : 'simplexml/libxml MISSING — SDK cannot run', true);

        $ossSdkAutoload = __DIR__ . '/sdk/oss/autoload.php';
        check('OSS SDK (alibabacloud/oss-v2)', is_file($ossSdkAutoload) && is_file(__DIR__ . '/sdk/oss/src/Client.php'),
            (is_file($ossSdkAutoload) && is_file(__DIR__ . '/sdk/oss/src/Client.php'))
                ? 'present — OSS uses official SDK' : 'MISSING — OSS falls back to built-in signing', true);

        $awsSdkAutoload = __DIR__ . '/sdk/aws/autoload.php';
        check('AWS SDK (aws/aws-sdk-php)', is_file($awsSdkAutoload) && is_file(__DIR__ . '/sdk/aws/Aws/S3/S3Client.php'),
            (is_file($awsSdkAutoload) && is_file(__DIR__ . '/sdk/aws/Aws/S3/S3Client.php'))
                ? 'present — S3 uses official SDK' : 'MISSING — S3 uploads will fail with a clear error', true);

        $obsSdkAutoload = __DIR__ . '/sdk/obs/obs-autoloader.php';
        check('OBS SDK (esdk-obs-php)', is_file($obsSdkAutoload) && is_file(__DIR__ . '/sdk/obs/Obs/ObsClient.php'),
            (is_file($obsSdkAutoload) && is_file(__DIR__ . '/sdk/obs/Obs/ObsClient.php'))
                ? 'present — OBS uses official SDK' : 'MISSING — OBS uploads will fail with a clear error', true);

        check('UPYUN SDK (upyun/sdk)', is_file(__DIR__ . '/sdk/upyun/autoload.php') && is_file(__DIR__ . '/sdk/upyun/src/Upyun/Upyun.php')
                && is_file(__DIR__ . '/sdk/cos/vendor/autoload.php'),
            (is_file(__DIR__ . '/sdk/upyun/autoload.php') && is_file(__DIR__ . '/sdk/upyun/src/Upyun/Upyun.php'))
                ? 'present — UPYUN uses official SDK' : 'MISSING — UPYUN uploads will fail with a clear error', true);

        check('Qiniu SDK (qiniu/php-sdk)', is_file(__DIR__ . '/sdk/qiniu/autoload.php') && is_file(__DIR__ . '/sdk/qiniu/src/Qiniu/Storage/UploadManager.php'),
            (is_file(__DIR__ . '/sdk/qiniu/autoload.php') && is_file(__DIR__ . '/sdk/qiniu/src/Qiniu/Storage/UploadManager.php'))
                ? 'present — Qiniu uses official SDK' : 'MISSING — Qiniu uploads will fail with a clear error', true);

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
            check('Storage profiles', false,
                'storage_profiles 表为空 — 未配置任何存储实例。请到后台「存储管理」新增存储实例。', true);
        }
    }
} else {
    check('Storage driver class', false, 'App\\Storage\\LocalDriver not found');
}

check('Signed links (short-lived)', is_file(__DIR__ . '/app/Controllers/FileController.php')
        && is_file(__DIR__ . '/app/Storage/SignedUrl.php'),
    (is_file(__DIR__ . '/app/Controllers/FileController.php') && is_file(__DIR__ . '/app/Storage/SignedUrl.php'))
        ? 'present — local /files endpoint + object-storage presign (no proxy)'
        : 'MISSING — file URLs would fall back to permanent links', true);

// 校验签名 key 稳定且不依赖数据库：sign()==verify() 且二次 sign() 结果相同。
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

section('Session persistence');

// 会话保存路径不可写会导致登录后数据丢失、被静默弹回登录页。
$sp = session_save_path();
if ($sp === '' || $sp === false) {
    $sp = sys_get_temp_dir();
}
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
// Redis/Memcached 的 save_path 是 URI，is_dir() 无法 stat，直接跳过。
$isRemoteScheme = is_string($sp) && preg_match('#^[a-z]+://#i', $sp);
if ($isRemoteScheme) {
    check('Session save path writable', true, $sp . ' (remote handler - verified by round-trip below)', true);
} else {
    $spWritable = is_dir($sp) && is_writable($sp);
    check('Session save path writable', $spWritable, $sp === '' ? 'empty (system default)' : $sp);
}

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
    // 实测 /config/database.php 是否可被公网读取：期望 403/404，200 需检查响应体。
    $isNginx = stripos($server, 'nginx') !== false;
    $htaccessPresent = is_file(__DIR__ . '/config/.htaccess');
    if (!$isNginx || !$htaccessPresent) {
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
        // HTTP 200 不一定是泄漏，宝塔/WAF 会返回 200 的拦截页，需看响应体区分。
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

section('Legacy cleanup');

$orphanSettingKeys = [
    'direct_upload_enabled',
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

// 不越出 open_basedir；@ 抑制警告 + is_readable 防护不可读路径。
$junkFiles = [];
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

$emptyDirs = [];
foreach (['backups', 'var', 'storage/logs'] as $rel) {
    $dir = __DIR__ . '/' . $rel;
    if (@is_dir($dir)) {
        $entries = @scandir($dir);
        if (is_array($entries) && count($entries) === 2) {
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

line();
line(str_repeat('=', 70));
line(sprintf('PASS %d   FAIL %d   WARN %d', $pass, $fail, $warn));
line($fail === 0 ? 'RESULT: deployment looks healthy.' : 'RESULT: fix the [FAIL] items above.');
line();
line('Remember to delete doctor.php after verification.');
