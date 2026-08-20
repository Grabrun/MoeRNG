<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * 临时链接签名工具（v1.2.0 迭代，v1.2.1 安全加固）：
 * - 本地存储下载端点 /files 使用 HMAC-SHA256 签名（key = expires + path）
 * - secret 使用独立随机签名密钥（config/signing_key.php，首次调用自动生成），
 *   不再从数据库密码派生——DB 密码泄露不再等于签名密钥泄露（审计 V-01）
 */
class SignedUrl
{
    /** Per-deploy secret from config/signing_key.php (auto-generated). */
    public static function secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }
        $keyFile = dirname(__DIR__, 2) . '/config/signing_key.php';
        if (is_file($keyFile)) {
            $cfg = include $keyFile;
            $secret = (string) ($cfg['signing_key'] ?? '');
        }
        if ($secret === '') {
            // First deploy (or upgrade): generate a fresh random key. If the
            // file can't be written we fall back to the legacy DB-derived
            // secret so downloads keep working instead of locking everyone out.
            $secret = bin2hex(random_bytes(32));
            try {
                file_put_contents(
                    $keyFile,
                    "<?php\n\nreturn ['signing_key' => '{$secret}'];\n",
                    LOCK_EX
                );
            } catch (\Throwable) {
                $dbConfig = dirname(__DIR__, 2) . '/config/database.php';
                $password = '';
                if (is_file($dbConfig)) {
                    $cfg = include $dbConfig;
                    $password = (string) ($cfg['password'] ?? '');
                }
                $secret = hash('sha256', 'moerng-signed-url:' . $password);
            }
        }
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
