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
     * v1.2.1 deep-audit fix: the nonce is PER-REQUEST (regenerated every time
     * sendSecurityHeaders runs), not session-persistent. A session-level nonce
     * stays constant for the whole login lifetime, which weakens the guarantee
     * (one leak = reusable forever) and would break any future full-page
     * caching (cached HTML would carry another user's nonce).
     * sendSecurityHeaders calls token() last before views render, so the value
     * in the CSP header and the one views embed are the same.
     */
    public static function get(): string
    {
        Session::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::generate();
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    /**
     * Return the raw nonce for the CSP header directive, regenerating it first
     * so the header always advertises this request's value.
     */
    public static function token(): string
    {
        self::generate();
        return (string) $_SESSION[self::SESSION_KEY];
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
