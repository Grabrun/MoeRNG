<?php
declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the bundled Alibaba Cloud OSS SDK v2
 * (alibabacloud/oss-v2). The SDK ships without a vendor directory, so it is
 * loaded here against src/ directly. Its runtime dependencies (Guzzle, PSR-7,
 * Promises, PSR http-message) are provided by the bundled COS SDK's vendor
 * directory (sdk/cos/vendor) — see OssSdkDriver::client().
 *
 * This mirrors the official autoload.php that ships with the package.
 */
function alibabacloudOssAutoloader(string $class): void
{
    if (!str_starts_with($class, 'AlibabaCloud\\Oss\\V2\\')) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('AlibabaCloud\\Oss\\V2\\'))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}
spl_autoload_register('alibabacloudOssAutoloader');
