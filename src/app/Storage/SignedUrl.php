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
     * HMAC signing secret, stored in config/signing_key.php (file, NOT DB).
     *
     * v1.2.1 security (audit V-01): the signing key is FULLY independent of
     * the database — neither derived FROM the DB password nor stored IN the
     * DB (a DB credential leak must not expose the signing key). Resolution:
     *   1) config/signing_key.php (authoritative file storage)
     *   2) fresh random 256-bit key written to the file; a failed write
     *      throws RuntimeException so ops must fix permissions — there is
     *      deliberately NO fallback that touches the database.
     */
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
        // Generate a fresh random key and persist it to the file.
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
        // Re-read to survive concurrent first calls that raced each other.
        $re = @include $keyFile;
        $written = is_array($re) ? (string) ($re['signing_key'] ?? '') : '';
        $secret = $written !== '' ? $written : $newKey;
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

    /**
     * v1.5.0-beta.1 性能修复: 签名有效期按**时间窗口对齐**。
     *
     * 此前 `expires = time() + ttl` —— 同一张图每秒都产生一个不同的 URL，
     * 浏览器把每次渲染都当作新资源，于是 `Cache-Control` 完全失效、每个页面
     * 浏览都要把图片全部重新下载一遍（"图片加载慢"的隐藏根因）。
     *
     * 现在把**签发时间**对齐到窗口起点，过期时间 = 窗口起点 + ttl：
     *   - 同一窗口内（默认 60s）对同一路径生成的 URL **完全一致** → 浏览器命中缓存
     *   - 剩余有效期恒在 [ttl - 窗口, ttl] 之间（默认 240~300s），不会瞬过期
     *   - 窗口 ≤ ttl；ttl 很短时窗口自动收缩，保证仍然可用
     *
     * 与响应头 `Cache-Control: private, max-age=60` 配套：缓存窗口 ≈ URL 轮换周期。
     */
    private const URL_WINDOW = 60;

    /** Build the signed local download URL (relative — resolves on the host). */
    public static function url(string $path, int $ttl): string
    {
        $ttl = max(1, $ttl);
        $window = min(self::URL_WINDOW, $ttl);
        $issued = intdiv(time(), $window) * $window;
        $expires = $issued + $ttl;
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
