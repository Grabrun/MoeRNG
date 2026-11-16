<?php
declare(strict_types=1);

namespace App\Core;

/** 每个请求一个 CSP nonce；所有 <script>/<style> 必须使用同一个值。 */
final class CspNonce
{
    private const SESSION_KEY = 'csp_nonce';

    public static function generate(): void
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    public static function get(): string
    {
        Session::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::generate();
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    private static ?string $issued = null;

    /** 本请求内幂等：header() 默认 replace 且视图读同一 session 值，两处不一致会拦掉所有内联脚本/样式。 */
    public static function token(): string
    {
        if (self::$issued !== null) {
            return self::$issued;
        }
        self::generate();
        return self::$issued = (string) $_SESSION[self::SESSION_KEY];
    }

    /** 返回 nonce 属性片段；无 nonce 时返回空串。 */
    public static function attr(): string
    {
        $n = self::get();
        return $n === '' ? '' : ' nonce="' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '"';
    }
}
