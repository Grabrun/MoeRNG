<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class ApiKey extends Model
{
    protected static string $table = 'api_keys';
    protected static array $fillable = [
        'name', 'key', 'permissions', 'rate_limit', 'rate_window', 'status'
    ];

    public static function generateKey(): string
    {
        return 'mr_' . bin2hex(random_bytes(24));
    }

    public static function findByKey(string $key): ?self
    {
        return self::firstWhere('key', $key);
    }

    public static function validate(string $key): ?self
    {
        $apiKey = self::findByKey($key);
        if (!$apiKey || $apiKey->status !== 'active') {
            return null;
        }
        return $apiKey;
    }

    public function getRateLimit(): int
    {
        return (int) ($this->rate_limit ?: 60);
    }

    public function getRateWindow(): int
    {
        return (int) ($this->rate_window ?: 60);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = json_decode($this->permissions ?: '[]', true);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
