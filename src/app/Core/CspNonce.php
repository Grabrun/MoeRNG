<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSP Nonce generator for this HTTP request.
 *
 * Every request gets one cryptographically random nonce (256-bit hex). All
 * <script nonce="..."> and <style nonce="..."> on that request must use it.
 * Inline event attributes (onclick=) remain blocked — migrate them to JS.
 *
 * 视图里只用 attr() 输出 nonce 属性；CSP 头本身由 token() 提供值。
 */
final class CspNonce
{
    private const SESSION_KEY = 'csp_nonce';

    /** Generate a new 256-bit hex nonce and persist it in the current session. */
    public static function generate(): void
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    /**
     * Get the current nonce for this request.
     *
     * v1.2.1 deep-audit fix: the nonce is PER-REQUEST, not session-persistent.
     * A session-level nonce stays constant for the whole login lifetime, which
     * weakens the guarantee (one leak = reusable forever) and would break any
     * future full-page caching (cached HTML would carry another user's nonce).
     */
    public static function get(): string
    {
        Session::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::generate();
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    /** 本请求已签发的 nonce（null = 尚未签发）。 */
    private static ?string $issued = null;

    /**
     * 本请求的 nonce —— **幂等**：同一请求内多次调用返回同一个值。
     *
     * v2.0.0-beta.19：此前每次调用都 `generate()` 出一个新值。`bootstrap()` 会调
     * `sendSecurityHeaders()` 两次（DB 加载前后各一次，第二次补 img-src），于是
     * 产生了两个 nonce —— 之所以没出故障，纯粹依赖两个默认行为：
     *   ① PHP `header()` 默认 `$replace = true`，第二个 CSP 头替换第一个；
     *   ② 视图读的是 session 里的**最后**那个值。
     * 只要有人把 header 换成追加（`false`）或调整调用顺序，头里的 nonce 与 HTML
     * 里的 nonce 就会不一致 → 所有内联脚本/样式被 CSP 拦掉，且极难定位。
     *
     * 「每请求一个」才是设计意图（见 get() 的注释），因此在请求内固定下来。
     */
    public static function token(): string
    {
        if (self::$issued !== null) {
            return self::$issued;
        }
        self::generate();
        return self::$issued = (string) $_SESSION[self::SESSION_KEY];
    }

    /**
     * Return the nonce wrapped as a HTML attribute, or empty string when none
     * is available (pre-install phase). Use conditional output to keep the HTML
     * valid regardless.
     */
    public static function attr(): string
    {
        $n = self::get();
        return $n === '' ? '' : ' nonce="' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '"';
    }
}
