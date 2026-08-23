<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = ['username', 'email', 'password', 'role', 'status', 'last_login', 'remember_token', 'remember_expires'];

    public function setPassword(string $password): void
    {
        $this->password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function findByEmail(string $email): ?self
    {
        return self::firstWhere('email', $email);
    }

    public static function findByUsername(string $username): ?self
    {
        return self::firstWhere('username', $username);
    }

    /** v1.2.1 登录增强: resolve a user by either email or username. */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        return self::findByEmail($login) ?: self::findByUsername($login);
    }

    // ── remember-me (auto-login) helpers ────────────────────────────────

    /** Create a fresh remember token; returns the RAW value for the cookie. */
    public function makeRememberToken(): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->remember_token = hash('sha256', $raw);
        $this->remember_expires = date('Y-m-d H:i:s', time() + 7 * 86400); // 7 days
        $this->save();
        return $raw;
    }

    /** Verify a raw remember cookie value against this user's stored hash. */
    public function verifyRememberToken(string $raw): bool
    {
        if ($this->remember_token === null || $this->remember_token === '' || $this->remember_expires === null) {
            return false;
        }
        if (strtotime((string) $this->remember_expires) < time()) {
            return false;
        }
        return hash_equals((string) $this->remember_token, hash('sha256', $raw));
    }

    /** Resolve a user from a raw remember cookie value (or null if invalid). */
    public static function findByRememberToken(string $raw): ?self
    {
        if ($raw === '') {
            return null;
        }
        $hash = hash('sha256', $raw);
        $user = self::firstWhere('remember_token', $hash);
        if (!$user || !$user->verifyRememberToken($raw)) {
            return null;
        }
        return $user;
    }

    /** Clear the remember token on logout (logout also removes the cookie). */
    public function clearRememberToken(): void
    {
        $this->remember_token = null;
        $this->remember_expires = null;
        $this->save();
    }
}
