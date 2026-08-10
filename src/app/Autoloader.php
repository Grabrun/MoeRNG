<?php
declare(strict_types=1);

namespace App;

/**
 * PSR-4 compliant autoloader.
 *
 * Fix history:
 *  - v1.0.1: register() now maps the root namespace ("App\") to the given base
 *            directory. Previously no prefix was ever registered, so every class
 *            fell through to a broken fallback that produced app/App/Core/Xxx.php
 *            instead of app/Core/Xxx.php, causing "Class not found" fatals.
 */
final class Autoloader
{
    /** @var array<string, list<string>> prefix => list of base directories */
    private static array $prefixes = [];

    private static bool $registered = false;

    /** @var list<string> attempted paths, populated only in debug mode */
    private static array $trace = [];

    /**
     * Register the autoloader and map the root namespace to $appPath.
     *
     * @param string $appPath       Absolute path to the directory holding App\ classes
     * @param string $rootNamespace Root namespace, defaults to "App"
     */
    public static function register(string $appPath, string $rootNamespace = 'App'): void
    {
        self::addNamespace($rootNamespace, $appPath);

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load'], true, false);
            self::$registered = true;
        }
    }

    /**
     * Map a namespace prefix to a base directory. Multiple directories per
     * prefix are supported and searched in registration order.
     */
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

    /**
     * Resolve and load a class. Returns the loaded file path, or false when the
     * class does not belong to any registered prefix (other autoloaders may handle it).
     */
    public static function load(string $class): string|false
    {
        $class = ltrim($class, '\\');
        $prefix = $class;

        // Walk the namespace from the most specific prefix down to the root.
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
                // require_once: load() may be invoked more than once for the
                // same class (e.g. a diagnostic tool probing the autoloader).
                // A plain require would redeclare the class and fatal.
                require_once $file;
                return $file;
            }

            if (self::debugEnabled()) {
                self::warnCaseMismatch($file);
            }
        }

        return false;
    }

    /**
     * On case-sensitive filesystems (Linux) a wrong-cased directory or filename
     * silently fails. Surface it explicitly instead of a bare "Class not found".
     */
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

    /** @return array<string, list<string>> */
    public static function registeredPrefixes(): array
    {
        return self::$prefixes;
    }

    /** @return list<string> Paths attempted since registration (debug mode only) */
    public static function trace(): array
    {
        return self::$trace;
    }
}
