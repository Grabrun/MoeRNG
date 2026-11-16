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
            // 校验会话用户仍存在且启用，避免残留 user_id 造成登录页无限跳转。
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

        $login = trim((string) $request->input('login', ''));
        $password = (string) $request->input('password', '');
        $remember = $request->input('remember') === '1';
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        // 按 IP 与用户名双维度锁定：防换 IP 爆破，同时抬高锁号攻击成本。
        $userBucket = $this->loginUserBucket($login);
        if ($this->loginLocked() || $this->userLocked($userBucket)) {
            AuditLog::record('login_blocked', ['ip' => $ip, 'reason' => 'lockout', 'login' => substr($login, 0, 120)]);
            Session::flash('error', '登录尝试过于频繁，请稍后再试。');
            $this->redirect('/admin/login');
            return;
        }

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

        // 成功登录后重置该 IP 与用户名的锁定计数。
        RateLimiter::reset($this->loginBucket($ip));
        RateLimiter::reset($userBucket);
        // 登录后轮换 session ID，防止会话固定。
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::set('user_id', $user->id);
        Session::set('user_role', $user->role);
        Session::set('user_name', (string) $user->username);
        if ($remember) {
            try {
                $rawToken = $user->makeRememberToken();
                $this->setRememberCookie($rawToken);
            } catch (\Throwable) {
            }
        }
        try {
            $user->last_login = date('Y-m-d H:i:s');
            $user->save();
        } catch (\Throwable) {
            // 登录不因缺少 last_login 列而失败。
        }
        AuditLog::record('login_success', ['ip' => $ip], (int) $user->id, (string) $user->username);
        $this->redirect('/admin');
    }

    public function logout(Request $request): void
    {
        // POST-only + CSRF；不用 CsrfMiddleware 是因为登出应 flash+302，而非 419 JSON。
        if (!Session::verifyCsrf((string) ($_POST['_csrf_token'] ?? ''))) {
            Session::flash('error', '登出请求无效或已过期，请重试。');
            $this->redirect('/admin');
            return;
        }
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw !== '') {
            try {
                $user = User::findByRememberToken($raw);
                if ($user) {
                    $user->clearRememberToken();
                }
            } catch (\Throwable) {
            }
            $this->setRememberCookie('', -1);
        }
        Session::destroy();
        $this->redirect('/admin/login');
    }

    private const REMEMBER_COOKIE = 'moerng_remember';

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

    private function loginBucket(string $ip): string
    {
        return 'login:' . ($ip !== '' ? $ip : 'unknown');
    }

    private function loginUserBucket(string $login): string
    {
        return 'login-user:' . mb_strtolower(trim($login));
    }

    private function userLocked(string $bucket): bool
    {
        if (trim(substr($bucket, strlen('login-user:'))) === '') {
            return false;
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

    private function loginLocked(): bool
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        return !RateLimiter::peek($this->loginBucket($ip), $this->loginMaxAttempts(), $this->loginWindow())[0];
    }

    private function loginLockSeconds(): int
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        return RateLimiter::peek($this->loginBucket($ip), $this->loginMaxAttempts(), $this->loginWindow())[2];
    }
}
