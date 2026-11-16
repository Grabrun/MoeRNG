<?php
declare(strict_types=1);

namespace App;

final class Autoloader
{
    private static array $prefixes = [];

    private static bool $registered = false;

    /** 仅调试模式下记录尝试过的路径 */
    private static array $trace = [];

    public static function register(string $appPath, string $rootNamespace = 'App'): void
    {
        self::addNamespace($rootNamespace, $appPath);

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load'], true, false);
            self::$registered = true;
        }
    }

    public static function addNamespace(string $prefix, string $baseDir, bool $prepend = false): void
    {
        $prefix  = trim($prefix, '\\') . '\\';
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';

        self::$prefixes[$prefix] ??= [];

        if (in_array($baseDir, self::$prefixes[$prefix], true)) {
            return;
        }

        if ($prepend) {
            array_unshift(self::$prefixes[$prefix], $baseDir);
        } else {
            self::$prefixes[$prefix][] = $baseDir;
        }
    }

    public static function load(string $class): string|false
    {
        $class = ltrim($class, '\\');
        $prefix = $class;

        // 从最具体的前缀向根命名空间逐级回退。
        while (false !== $pos = strrpos($prefix, '\\')) {
            $prefix   = substr($class, 0, $pos + 1);
            $relative = substr($class, $pos + 1);

            if ($file = self::loadMapped($prefix, $relative)) {
                return $file;
            }

            $prefix = rtrim($prefix, '\\');
        }

        return false;
    }

    private static function loadMapped(string $prefix, string $relative): string|false
    {
        if (!isset(self::$prefixes[$prefix])) {
            return false;
        }

        foreach (self::$prefixes[$prefix] as $baseDir) {
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

            if (self::debugEnabled()) {
                self::$trace[] = $file;
            }

            if (is_file($file)) {
                // 同一类可能被重复触发 autoload，普通 require 会重声明类导致致命错误。
                require_once $file;
                return $file;
            }

            if (self::debugEnabled()) {
                self::warnCaseMismatch($file);
            }
        }

        return false;
    }

    /** 大小写敏感文件系统上文件名大小写不符会静默失败，这里显式告警。 */
    private static function warnCaseMismatch(string $expected): void
    {
        $dir  = dirname($expected);
        $base = basename($expected);

        if (!is_dir($dir)) {
            return;
        }

        foreach ((scandir($dir) ?: []) as $entry) {
            if ($entry !== $base && strcasecmp($entry, $base) === 0) {
                trigger_error(
                    "Autoloader: case mismatch, expected '{$base}' but found '{$entry}' in {$dir}",
                    E_USER_WARNING
                );
                return;
            }
        }
    }

    private static function debugEnabled(): bool
    {
        return defined('MOERNG_DEBUG_AUTOLOAD') && constant('MOERNG_DEBUG_AUTOLOAD');
    }

    public static function registeredPrefixes(): array
    {
        return self::$prefixes;
    }

}
