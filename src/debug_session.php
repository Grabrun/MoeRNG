<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

\App\Core\Session::start();
header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP / OPcache (running process) ===\n";
echo "php_version: " . phpversion() . "\n";
echo "sapi: " . php_sapi_name() . "\n";
echo "opcache_loaded: " . var_export(extension_loaded('Zend OPcache'), true) . "\n";
echo "opcache.enable: " . var_export(ini_get('opcache.enable'), true) . "\n";

$ac = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$authFile = realpath(__DIR__ . '/app/Controllers/Admin/AuthController.php');
if ($ac && !empty($ac['scripts']) && $authFile && isset($ac['scripts'][$authFile])) {
    $s = $ac['scripts'][$authFile];
    echo "AuthController cached by OPcache: YES\n";
    echo "  cached_file_mtime: " . date('Y-m-d H:i:s', (int) $s['timestamp']) . "\n";
    echo "  on_disk_mtime:      " . date('Y-m-d H:i:s', (int) @filemtime($authFile)) . "\n";
} elseif ($ac && !empty($ac['scripts'])) {
    echo "AuthController cached by OPcache: NO (not in this pool's cache)\n";
} else {
    echo "opcache status unavailable (opcache may be disabled in this pool)\n";
}

echo "\n=== Request / Session ===\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'n/a') . "\n";
echo "PHPSESSID cookie: " . ($_COOKIE['PHPSESSID'] ?? '(none)') . "\n";
echo "session_id(): " . session_id() . "\n";
echo "session_status(): " . session_status() . " (1=none, 2=active, 3=disabled)\n";
echo "session.save_path: " . (session_save_path() ?: ini_get('session.save_path') ?: '(default)') . "\n";
echo "\$_SESSION = "; var_export($_SESSION); echo "\n";

$uid = $_SESSION['user_id'] ?? null;
echo "user_id: " . var_export($uid, true) . "\n";
if ($uid !== null) {
    try {
        $user = \App\Models\User::find($uid);
        if ($user) {
            echo "User::find($uid) => FOUND (role={$user->role}, status={$user->status}, isActive=" . var_export($user->isActive(), true) . ")\n";
        } else {
            echo "User::find($uid) => NOT FOUND\n";
        }
    } catch (\Throwable $e) {
        echo "User::find($uid) THREW: " . $e->getMessage() . "\n";
    }
}

echo "\n=== What loginForm WOULD do ===\n";
if ($uid === null) {
    echo "no user_id -> would RENDER login form (200), no redirect\n";
} else {
    $u = isset($user) ? $user : null;
    if ($u && $u->isActive()) {
        echo "valid active user -> would 302 to /admin\n";
    } else {
        echo "invalid/stale user -> would clear session and RENDER login form (200)\n";
    }
}
echo "\n(delete this file after use)\n";
