<?php
declare(strict_types=1);

/**
 * MoeRNG - Shared bootstrap.
 *
 * Single place where autoloading is wired up. All four front controllers
 * (index.php / api.php / admin.php / install.php) require this file, so the
 * autoload strategy can never drift between entry points again.
 */

if (defined('MOERNG_BOOTSTRAPPED')) {
    return;
}
define('MOERNG_BOOTSTRAPPED', true);

if (!defined('MOERNG_START')) {
    define('MOERNG_START', microtime(true));
}

define('MOERNG_ROOT', __DIR__);

// Release version — surfaced in the footer and the /api/v1/stats endpoint.
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.1.1-beta.5');
}

/* -------------------------------------------------------------------------
 * Autoloader
 * ---------------------------------------------------------------------- */

$autoloaderFile = __DIR__ . '/app/Autoloader.php';

if (!is_file($autoloaderFile)) {
    moerng_bootstrap_fail(
        'app/Autoloader.php is missing.',
        'The upload is incomplete. Re-upload the release archive, making sure the app/ directory is included.'
    );
}

require_once $autoloaderFile;

// Map the root namespace App\ to the app/ directory (PSR-4).
\App\Autoloader::register(__DIR__ . '/app');

/* -------------------------------------------------------------------------
 * Sanity check
 *
 * Fail with an actionable message instead of a bare
 * "Class App\Core\Application not found" fatal.
 * ---------------------------------------------------------------------- */

if (!class_exists(\App\Core\Application::class)) {
    $expected = __DIR__ . '/app/Core/Application.php';

    $hint = match (true) {
        !is_file($expected)   => "Expected file not found: {$expected}",
        !is_readable($expected) => "File exists but is not readable: {$expected} (check owner/permissions, e.g. chown -R www:www)",
        default               => "File exists and is readable but the class did not load. Check for a syntax error or a mismatched namespace declaration in {$expected}.",
    };

    moerng_bootstrap_fail('App\\Core\\Application could not be autoloaded.', $hint);
}

/* -------------------------------------------------------------------------
 * Global helpers
 * ---------------------------------------------------------------------- */

$helpers = __DIR__ . '/app/helpers.php';
if (is_file($helpers)) {
    require_once $helpers;
}

/* -------------------------------------------------------------------------
 * Failure handler
 * ---------------------------------------------------------------------- */

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
