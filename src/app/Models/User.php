<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = ['username', 'email', 'password', 'role', 'status'];

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
}
