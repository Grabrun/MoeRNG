p = 'src/app/Core/CspNonce.php'
s = open(p, encoding='utf-8').read()

old = '''    /**
     * Get the current nonce from the session, generating one if missing.
     * Call early (e.g. in bootstrap or the first Controller) so all views
     * see the same nonce for the request.
     */
    public static function get(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::generate();
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    /**
     * Return the raw nonce string (no quotes, no attribute name) for use
     * inside a CSP header directive: script-src 'self' 'nonce-{value}'.
     */
    public static function token(): string
    {
        return self::get();
    }'''

new = '''    /**
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
    }'''

if old in s:
    s = s.replace(old, new, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('OK: per-request nonce')
else:
    print('MISS')
