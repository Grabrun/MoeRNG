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
    /**
     * HMAC signing secret, stored in the DATABASE (settings.signing_key).
     *
     * v1.2.1 security (audit V-01): the signing key is FULLY independent —
     * there is NO database-PASSWORD derivation. Resolution order:
     *   1) settings.signing_key (authoritative storage)
     *   2) legacy config/signing_key.php → migrated into settings on first
     *      access (keeps already-issued URLs valid), then the file is removed
     *   3) fresh random 256-bit key persisted to settings
     * Any failure to read/write settings surfaces as an exception (loud).
     */
    public static function secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }
        // 1) DB-backed key (settings table).
        $stored = (string) \App\Models\Setting::get('signing_key', '');
        if ($stored !== '') {
            $secret = $stored;
            return $secret;
        }
        // 2) Legacy file key → migrate into the DB (keeps URLs valid).
        $keyFile = dirname(__DIR__, 2) . '/config/signing_key.php';
        if (is_file($keyFile)) {
            $cfg = include $keyFile;
            $fileKey = (string) ($cfg['signing_key'] ?? '');
            if ($fileKey !== '') {
                \App\Models\Setting::set('signing_key', $fileKey);
                @unlink($keyFile); // file superseded by DB storage
                $secret = $fileKey;
                return $secret;
            }
        }
        // 3) Generate a fresh random key and persist it in the DB.
        $newKey = bin2hex(random_bytes(32));
        \App\Models\Setting::set('signing_key', $newKey);
        $secret = $newKey;
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
