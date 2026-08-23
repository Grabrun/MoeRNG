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
 * Usage in controllers / views:
 *   echo CspNonce::htmlMeta();         // <meta name="csp-nonce" ...>
 *   echo CspNonce::attr();             // nonce="x..."
 *   CspNonce::requireNonce();          // dies with 500 if no session (pre-flight)
 */
final class CspNonce
{
    private const SESSION_KEY = 'csp_nonce';

    /** Generate a new 256-bit hex nonce and persist it in the current session. */
    public static function generate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    /**
     * Get the current nonce for this request.
     *
     * v1.2.1 deep-audit fix: the nonce is now PER-REQUEST (regenerated every
     * time sendSecurityHeaders runs), not session-persistent. A session-level
     * nonce stays constant for the whole login lifetime, which weakens the
     * guarantee (one leak = reusable forever) and would break any future
     * full-page caching (cached HTML would carry another user's nonce).
     * sendSecurityHeaders calls token() last before views render, so the
     * value in the CSP header and the one views embed are the same.
     */
    public static function get(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::generate();
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    /**
     * Return the raw nonce string for the CSP header directive AND mark it
     * as the request's canonical value (regenerated per request).
     */
    public static function token(): string
    {
        self::generate();
        return (string) $_SESSION[self::SESSION_KEY];
    }

    /**
     * Return the nonce value wrapped as a HTML attribute, or empty string
     * when no nonce is available (pre-install / setup phase where CSP is
     * sent without script-src). Use conditional output to keep the HTML
     * valid regardless.
     */
    public static function attr(): string
    {
        $n = self::get();
        return $n === '' ? '' : ' nonce="' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * <meta name="csp-nonce" content="..."> for JS consumers that need to
     * read the nonce programmatically (e.g. dynamic script injection).
     */
    public static function htmlMeta(): string
    {
        return '<meta name="csp-nonce" content="' . htmlspecialchars(self::get(), ENT_QUOTES, 'UTF-8') . '">' . "\n    ";
    }

    /**
     * Assert a nonce exists. Used by controllers that depend on a live
     * session — if the session hasn't been started yet the site is
     * misconfigured and should fail loudly rather than silently fall
     * back to unsafe-inline.
     */
    public static function requireNonce(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            http_response_code(500);
            exit('CSP nonce unavailable — session could not be started.');
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::generate();
        }
    }
}
