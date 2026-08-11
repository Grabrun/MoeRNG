<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * 临时链接签名工具（v1.2.0 迭代）：
 * - 本地存储下载端点 /files 使用 HMAC-SHA256 签名（key = expires + path）
 * - secret 由数据库密码派生（部署不换库则签名稳定，无需新增配置）
 */
class SignedUrl
{
    /** Stable per-deploy secret derived from the DB password. */
    public static function secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }
        $dbConfig = dirname(__DIR__, 2) . '/config/database.php';
        $password = '';
        if (is_file($dbConfig)) {
            $cfg = include $dbConfig;
            $password = (string) ($cfg['password'] ?? '');
        }
        $secret = hash('sha256', 'moerng-signed-url:' . $password);
        return $secret;
    }

    /** Sign a download URL: sig = HMAC-SHA256(expires|path, secret). */
    public static function sign(string $path, int $expires): string
    {
        return hash_hmac('sha256', $expires . '|' . $path, self::secret());
    }

    /** Verify a download URL. Returns true when valid and not expired. */
    public static function verify(string $path, int $expires, string $sig): bool
    {
        if ($expires <= time()) {
            return false;
        }
        return hash_equals(self::sign($path, $expires), (string) $sig);
    }

    /** Build the signed local download URL (relative — resolves on the host). */
    public static function url(string $path, int $ttl): string
    {
        $expires = time() + max(1, $ttl);
        $p = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
        return '/files?p=' . $p . '&e=' . $expires . '&s=' . self::sign($path, $expires);
    }

    /** Decode the base64url path segment. */
    public static function decodePath(string $p): string
    {
        $b64 = strtr($p, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($b64, true);
    }
}
