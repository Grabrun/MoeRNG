<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * GD-based login captcha (v1.1.0-beta.4). Gated by settings.login_captcha.
 * The code is stored in the session and consumed on first verify (one-time).
 */
class Captcha
{
    private const SESSION_KEY = 'captcha_code';
    private const WIDTH = 126;
    private const HEIGHT = 42;

    /** Whether the captcha feature is enabled (settings.login_captcha). */
    public static function enabled(): bool
    {
        return Setting::get('login_captcha', '0') === '1';
    }

    /** Generate a 5-char code (no confusables: 0/O/1/I/l removed). */
    public static function generate(): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        Session::set(self::SESSION_KEY, strtolower($code));
        return $code;
    }

    /** Render the captcha image directly (used by GET /admin/captcha). */
    public static function render(): void
    {
        self::generate();
        if (!extension_loaded('gd')) {
            http_response_code(500);
            exit('GD extension required for captcha');
        }
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $bg = imagecolorallocate($img, 246, 248, 252);
        imagefill($img, 0, 0, $bg);

        // Noise dots + 3 wavy lines — cheap but enough to defeat bots while
        // staying readable for humans.
        for ($i = 0; $i < 80; $i++) {
            imagesetpixel(
                $img,
                random_int(0, self::WIDTH - 1),
                random_int(0, self::HEIGHT - 1),
                imagecolorallocate($img, random_int(130, 220), random_int(130, 220), random_int(130, 220))
            );
        }
        for ($i = 0; $i < 3; $i++) {
            imageline(
                $img,
                random_int(0, (int)(self::WIDTH / 3)), random_int(0, self::HEIGHT),
                random_int((int)(self::WIDTH / 3 * 2), self::WIDTH), random_int(0, self::HEIGHT),
                imagecolorallocate($img, random_int(150, 215), random_int(150, 215), random_int(150, 215))
            );
        }

        $colors = [
            imagecolorallocate($img, 45, 85, 160),
            imagecolorallocate($img, 170, 60, 55),
            imagecolorallocate($img, 40, 140, 95),
        ];
        $code = Session::get(self::SESSION_KEY, '');
        $x = 14;
        for ($i = 0; $i < 5; $i++) {
            imagestring($img, 5, $x, 12 + random_int(-3, 3), strtoupper($code[$i] ?? '?'), $colors[array_rand($colors)]);
            $x += 20;
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    /** One-time verify: consumes the stored code regardless of outcome. */
    public static function verify(?string $input): bool
    {
        $expected = (string) Session::get(self::SESSION_KEY, '');
        Session::remove(self::SESSION_KEY);
        if ($expected === '') {
            return false;
        }
        return strtolower(trim((string) $input)) === $expected;
    }
}
