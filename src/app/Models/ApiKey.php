<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class ApiKey extends Model
{
    protected static string $table = 'api_keys';

    /**
     * `permissions` 是预留字段，当前**不参与鉴权** —— ApiKeyAuthMiddleware 只校验
     * Key 有效且 status='active'。/api/v1/* 现全为只读端点，故暂无实际越权面。
     * ⚠️ 一旦新增任何写端点，必须同时实现权限校验，否则这个「已配好的权限系统」
     * 会给人虚假的安全感。后台表单已就此给出提示。
     */
    protected static array $fillable = [
        'name', 'key_hash', 'key_prefix', 'permissions', 'rate_limit', 'rate_window', 'status'
    ];

    public static function generateKey(): string
    {
        return 'mr_' . bin2hex(random_bytes(24));
    }

    /**
     * SHA-256 of a plaintext key — the only form ever stored/looked up.
     * Same one-way pattern as User::makeRememberToken() (users.remember_token).
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function findByKey(string $key): ?self
    {
        // MR-03: never look the plaintext up — the DB only holds its hash.
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
