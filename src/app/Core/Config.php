<?php
declare(strict_types=1);

namespace App\Core;

class Config
{
    private static array $items = [];
    private static bool $loaded = false;
    private static string $configPath;

    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // 发布包不含 config/，缺失时生成默认 app.php 供安装器启动；解压更新不会覆盖线上配置。
        $appFile = self::$configPath . '/app.php';
        if (!is_file($appFile)) {
            if (!is_dir(self::$configPath)) {
                @mkdir(self::$configPath, 0755, true);
            }
            if (is_dir(self::$configPath) && is_writable(self::$configPath)) {
                $default = "<?php\n\nreturn [\n    'installed' => false,\n    'base_url'  => '',\n\n    // 自托管内网邮件服务器（局域网 Postfix/MailHog）需设为 true，\n    // 放行 SMTP 指向私网/回环地址；云元数据 169.254.169.254 始终拒绝。\n    // 'allow_private_smtp' => false,\n];\n";
                @file_put_contents($appFile, $default, LOCK_EX);
            }
        }

        $files = glob(self::$configPath . '/*.php') ?: [];
        foreach ($files as $file) {
            $key = basename($file, '.php');
            $data = require $file;
            if (is_array($data)) {
                self::$items[$key] = $data;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = self::$items;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $ref = &self::$items;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $ref[$segment] = $value;
            } else {
                if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }
        }
    }

    public static function all(): array
    {
        return self::$items;
    }

    public static function reload(): void
    {
        self::$loaded = false;
        self::$items = [];
        self::load();
    }
}
