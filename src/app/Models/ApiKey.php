<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class ApiKey extends Model
{
    protected static string $table = 'api_keys';

    // permissions 是预留字段，当前不参与鉴权；加写端点时必须同步实现权限校验。
    protected static array $fillable = [
        'name', 'key_hash', 'key_prefix', 'permissions', 'rate_limit', 'rate_window', 'status'
    ];

    public static function generateKey(): string
    {
        return 'mr_' . bin2hex(random_bytes(24));
    }

    /** 只存 SHA-256 哈希，查库也只按哈希查。 */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function findByKey(string $key): ?self
    {
        return self::firstWhere('key_hash', self::hashKey($key));
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

}
