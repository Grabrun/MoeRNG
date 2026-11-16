<?php
declare(strict_types=1);

namespace App\Storage;

/** 本地 /files 下载链接签名（HMAC-SHA256），密钥独立于数据库。 */
class SignedUrl
{
    /** 签名密钥存 config/signing_key.php，写不进去直接抛错，绝不回退数据库。 */
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
            if ($secret !== '') {
                return $secret;
            }
        }
        $newKey = bin2hex(random_bytes(32));
        $ok = @file_put_contents(
            $keyFile,
            "<?php\n\nreturn ['signing_key' => '{$newKey}'];\n",
            LOCK_EX
        );
        if ($ok === false) {
            throw new \RuntimeException(
                '[MoeRNG] Cannot write ' . $keyFile . ' — make config/ writable '
                . 'so the HMAC signing key can be persisted (no DB fallback).'
            );
        }
        // 0640 防止同机其他账号读取密钥伪造签名链接（Windows 上是 no-op）。
        @chmod($keyFile, 0640);
        // 重新读取，兼容并发首次调用。
        $re = @include $keyFile;
        $written = is_array($re) ? (string) ($re['signing_key'] ?? '') : '';
        $secret = $written !== '' ? $written : $newKey;
        return $secret;
    }

    /** sig = HMAC-SHA256(expires|path, secret)。 */
    public static function sign(string $path, int $expires): string
    {
        return hash_hmac('sha256', $expires . '|' . $path, self::secret());
    }

    public static function verify(string $path, int $expires, string $sig): bool
    {
        if ($expires <= time()) {
            return false;
        }
        return hash_equals(self::sign($path, $expires), (string) $sig);
    }

    /** 签发时间对齐到 60s 窗口：同窗口内同一路径 URL 相同，浏览器才能命中缓存。 */
    private const URL_WINDOW = 60;

    public static function alignedExpiry(int $ttl): int
    {
        $ttl = max(1, $ttl);
        $window = min(self::URL_WINDOW, $ttl);
        $issued = intdiv(time(), $window) * $window;
        return $issued + $ttl;
    }

    public static function url(string $path, int $ttl): string
    {
        $expires = self::alignedExpiry($ttl);
        $p = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
        return '/files?p=' . $p . '&e=' . $expires . '&s=' . self::sign($path, $expires);
    }

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
