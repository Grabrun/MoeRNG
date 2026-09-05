<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Core\Captcha;
use App\Core\RateLimiter;
use App\Models\User;
use App\Models\Setting;
use App\Models\AuditLog;

class AuthController extends Controller
{
    public function loginForm(Request $request): void
    {
        $userId = Session::get('user_id');
        if ($userId) {
            // Only treat the session as "already logged in" when the stored id
            // actually maps to an existing, active user. A stale or orphaned
            // user_id (e.g. left over from a reset database) used to bounce
            // between /admin and /admin/login forever. Validating here breaks
            // that loop and lets the user reach the form to log in again.
            $user = User::find($userId);
            if ($user && $user->isActive()) {
                $this->redirect('/admin');
            }
            Session::remove('user_id');
            Session::remove('user_role');
        }
        $this->render('admin/login', [
            'error' => Session::flash('error'),
            'captcha_enabled' => Captcha::enabled(),
            'locked' => $this->loginLocked(),
            'lock_seconds' => $this->loginLockSeconds(),
        ]);
    }

    public function login(Request $request): void
    {
        $this->validateCsrf();

        $login = trim((string) $request->input('login', ''));
        $password = (string) $request->input('password', '');
        $remember = $request->input('remember') === '1';
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        // 1) Login lockout (settings.login_max_attempts / login_lockout_minutes).
        //    v1.3.0-beta.2 (CVE-2026-MR-016): lock on TWO dimensions — per-IP
        //    (strict) and per-username (looser, 4x the attempts) so a botnet
        //    rotating IPs cannot brute-force one account, while an attacker
        //    deliberately spamming a victim's username needs 4x the traffic to
        //    cause a lock (limits the lockout-DoS surface).
        $userBucket = $this->loginUserBucket($login);
        if ($this->loginLocked() || $this->userLocked($userBucket)) {
            AuditLog::record('login_blocked', ['ip' => $ip, 'reason' => 'lockout', 'login' => substr($login, 0, 120)]);
            Session::flash('error', '登录尝试过于频繁，请稍后再试。');
            $this->redirect('/admin/login');
            return;
        }

        // 2) Captcha (settings.login_captcha).
        if (Captcha::enabled() && !Captcha::verify((string) $request->input('captcha', ''))) {
            AuditLog::record('login_failed', ['ip' => $ip, 'reason' => 'captcha', 'login' => substr($login, 0, 120)]);
            Session::flash('error', '验证码错误，请重新输入。');
            $this->redirect('/admin/login');
            return;
        }

        $user = User::findByLogin($login);
        if (!$user || !$user->verifyPassword($password)) {
            RateLimiter::hit($this->loginBucket($ip), $this->loginMaxAttempts(), $this->loginWindow());
            if ($login !== '') {
                RateLimiter::hit($userBucket, $this->loginMaxAttempts() * 4, $this->loginWindow());
            }
            AuditLog::record('login_failed', ['ip' => $ip, 'reason' => 'credentials', 'login' => substr($login, 0, 120)]);
            Session::flash('error', '用户名/邮箱或密码错误。');
            $this->redirect('/admin/login');
            return;
        }

        if (!$user->isActive()) {
            AuditLog::record('login_failed', ['ip' => $ip, 'reason' => 'disabled', 'login' => substr($login, 0, 120)]);
            Session::flash('error', '账号已被禁用。');
            $this->redirect('/admin/login');
            return;
        }

        // Success: reset the lockout counters for this IP + this username + audit.
        RateLimiter::reset($this->loginBucket($ip));
        RateLimiter::reset($userBucket);
        // v1.2.1 security (session fixation): rotate the session ID on login
        // so an attacker-supplied PHPSESSID can never be used post-auth.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::set('user_id', $user->id);
        Session::set('user_role', $user->role);
        Session::set('user_name', (string) $user->username);
        // v1.2.1 登录增强: remember-me — issue a hashed token + HttpOnly cookie
        // so the browser can auto-login on a later visit within 7 days.
        if ($remember) {
            try {
                $rawToken = $user->makeRememberToken();
                $this->setRememberCookie($rawToken);
            } catch (\Throwable) {
                // best-effort — remember-me must never block an otherwise-good login
            }
        }
        // v1.2.1 UI 深度分析 (UI-10): record last login timestamp.
        try {
            $user->last_login = date('Y-m-d H:i:s');
            $user->save();
        } catch (\Throwable) {
            // best-effort — login must never fail because of a missing column
        }
        AuditLog::record('login_success', ['ip' => $ip], (int) $user->id, (string) $user->username);
        $this->redirect('/admin');
    }

    public function logout(Request $request): void
    {
        // v1.3.0-beta.2 安全加固 (CVE-2026-MR-005): the route is POST-only and
        // requires a valid CSRF token — a plain <img src="/admin/logout"> can
        // no longer force-logout a logged-in admin. Deliberately NOT using
        // Controller::validateCsrf() here: its 419-JSON answer is meant for
        // AJAX flows, while logout is a navigation action — a stale/expired
        // token just bounces the user back to the dashboard with a flash.
        // Validation MUST run before Session::destroy() wipes the token.
        if (!Session::verifyCsrf((string) ($_POST['_csrf_token'] ?? ''))) {
            Session::flash('error', '登出请求无效或已过期，请重试。');
            $this->redirect('/admin');
            return;
        }
        // v1.2.1 登录增强: revoke any remember-me token before destroying the
        // session, so the browser cookie can no longer auto-login.
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw !== '') {
            try {
                $user = User::findByRememberToken($raw);
                if ($user) {
                    $user->clearRememberToken();
                }
            } catch (\Throwable) {
                // best-effort — logout must always succeed
            }
            $this->setRememberCookie('', -1);
        }
        Session::destroy();
        $this->redirect('/admin/login');
    }

    // ── remember-me cookie helpers ───────────────────────────────────────

    private const REMEMBER_COOKIE = 'moerng_remember';

    /** Set (or clear, when $value==='' & $expire<0) the HttpOnly remember cookie. */
    private function setRememberCookie(string $value, int $lifetime = 7 * 86400): void
    {
        $expires = $value === '' ? time() - 3600 : time() + $lifetime;
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == '443',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // ── login lockout helpers ────────────────────────────────────────────

    private function loginBucket(string $ip): string
    {
        return 'login:' . ($ip !== '' ? $ip : 'unknown');
    }

    /** v1.3.0-beta.2 (CVE-2026-MR-016): per-username lockout bucket. */
    private function loginUserBucket(string $login): string
    {
        return 'login-user:' . mb_strtolower(trim($login));
    }

    /** Whether the username bucket is in lockout (read-only). */
    private function userLocked(string $bucket): bool
    {
        if (trim(substr($bucket, strlen('login-user:'))) === '') {
            return false; // empty login — nothing to lock on
        }
        return !RateLimiter::peek($bucket, $this->loginMaxAttempts() * 4, $this->loginWindow())[0];
    }

    private function loginMaxAttempts(): int
    {
        return max(1, (int) (Setting::get('login_max_attempts', '5') ?: 5));
    }

    private function loginWindow(): int
    {
        return max(1, (int) Setting::get('login_lockout_minutes', '15') * 60);
    }

    /** Whether this IP is currently in the login lockout window (read-only). */
    private function loginLocked(): bool
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        return !RateLimiter::peek($this->loginBucket($ip), $this->loginMaxAttempts(), $this->loginWindow())[0];
    }

    /** Seconds remaining until the lockout expires (read-only, for the form). */
    private function loginLockSeconds(): int
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        return RateLimiter::peek($this->loginBucket($ip), $this->loginMaxAttempts(), $this->loginWindow())[2];
    }
}
