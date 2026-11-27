<?php
declare(strict_types=1);

/**
 * MoeRNG - Shared bootstrap.
 */

if (defined('MOERNG_BOOTSTRAPPED')) {
    return;
}
define('MOERNG_BOOTSTRAPPED', true);

if (!defined('MOERNG_START')) {
    define('MOERNG_START', microtime(true));
}

define('MOERNG_ROOT', __DIR__);

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.1.0-beta.4');
}

// 缓存戳含资源 mtime，改 CSS/JS 而未升版本号时也能刷新缓存。
if (!defined('ASSET_VER')) {
    $assetMtime = 0;
    foreach (['public/css/style.css', 'public/js/app.js', 'public/js/helpers.js'] as $assetFile) {
        $m = @filemtime(__DIR__ . '/' . $assetFile);
        if ($m !== false && $m > $assetMtime) {
            $assetMtime = $m;
        }
    }
    define('ASSET_VER', APP_VERSION . '.' . $assetMtime);
    unset($assetFile, $assetMtime, $m);
}

$autoloaderFile = __DIR__ . '/app/Autoloader.php';

if (!is_file($autoloaderFile)) {
    moerng_bootstrap_fail(
        'app/Autoloader.php is missing.',
        'The upload is incomplete. Re-upload the release archive, making sure the app/ directory is included.'
    );
}

require_once $autoloaderFile;

\App\Autoloader::register(__DIR__ . '/app');

if (!class_exists(\App\Core\Application::class)) {
    $expected = __DIR__ . '/app/Core/Application.php';

    $hint = match (true) {
        !is_file($expected)   => "Expected file not found: {$expected}",
        !is_readable($expected) => "File exists but is not readable: {$expected} (check owner/permissions, e.g. chown -R www:www)",
        default               => "File exists and is readable but the class did not load. Check for a syntax error or a mismatched namespace declaration in {$expected}.",
    };

    moerng_bootstrap_fail('App\\Core\\Application could not be autoloaded.', $hint);
}

$helpers = __DIR__ . '/app/helpers.php';
if (is_file($helpers)) {
    require_once $helpers;
}

function moerng_bootstrap_fail(string $problem, string $hint): never
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "MoeRNG bootstrap failed\n";
    echo str_repeat('-', 60) . "\n";
    echo "Problem : {$problem}\n";
    echo "Hint    : {$hint}\n";
    echo "Root    : " . __DIR__ . "\n";
    echo "PHP     : " . PHP_VERSION . "\n";
    echo str_repeat('-', 60) . "\n";
    echo "Run doctor.php in this directory for a full diagnostic report.\n";

    exit(1);
}

// CLI 不走 Application::create()，配置与数据库凭据得在这里补 —— 否则 Database
// 用空配置连 127.0.0.1:3306，在 socket-only / 非默认端口的 MySQL 上必报 2002。
if (PHP_SAPI === 'cli') {
    \App\Core\Application::bootstrapCli(__DIR__);
}
