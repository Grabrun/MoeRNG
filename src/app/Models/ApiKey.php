<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class ApiKey extends Model
{
    protected static string $table = 'api_keys';

    // api_keys.permissions 列保留但不再有写入/读取入口：没有鉴权执行点的权限勾选只会误导运维。
    protected static array $fillable = [
        'name', 'key_hash', 'key_prefix', 'rate_limit', 'rate_window', 'status'
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

    /** @var array<string, self|null> 请求级缓存：鉴权中间件与限流中间件会查同一个 key。 */
    private static array $byKeyCache = [];

    public static function findByKey(string $key): ?self
    {
        $hash = self::hashKey($key);
        if (array_key_exists($hash, self::$byKeyCache)) {
            return self::$byKeyCache[$hash];
        }
        return self::$byKeyCache[$hash] = self::firstWhere('key_hash', self::hashKey($key));
    }

    public function getRateLimit(): int
    {
        return (int) ($this->rate_limit ?: 60);
    }

}
