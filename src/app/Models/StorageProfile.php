<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;
use App\Storage\StorageInterface;

/** 一个命名的独立存储实例：driver = local|s3，provider 仅 s3 使用。 */
class StorageProfile extends Model
{
    protected static string $table = 'storage_profiles';
    protected static array $fillable = [
        'name', 'driver', 'provider', 'config', 'is_default', 'enabled', 'sort_order',
    ];

    /** @var array<int, self|null> 请求级实例缓存（save() 后清空）。 */
    private static array $rowCache = [];

    /** @var array<int, \App\Storage\StorageInterface> */
    private static array $driverCache = [];

    private static ?self $defaultCache = null;
    private static bool $defaultResolved = false;

    public static function flushCache(): void
    {
        self::$rowCache = [];
        self::$driverCache = [];
        self::$defaultCache = null;
        self::$defaultResolved = false;
    }

    /** 按 id 解析实例，缺失或无效回落到默认实例（唯一实现）。 */
    public static function resolve(?int $profileId): ?self
    {
        if ($profileId !== null && $profileId > 0) {
            return self::find($profileId) ?? self::defaultProfile();
        }
        return self::defaultProfile();
    }

    public static function find(int|string $id): ?static
    {
        $key = (int) $id;
        if (array_key_exists($key, self::$rowCache)) {
            return self::$rowCache[$key];
        }
        return self::$rowCache[$key] = parent::find($id);
    }

    /** 驱动构造代价高（SDK 客户端、凭据解密、realpath），同请求内复用。 */
    public static function cachedDriver(self $profile): StorageInterface
    {
        $key = (int) ($profile->attributes['id'] ?? 0);
        if ($key > 0 && isset(self::$driverCache[$key])) {
            return self::$driverCache[$key];
        }
        $driver = $profile->driver();
        if ($key > 0) {
            self::$driverCache[$key] = $driver;
        }
        return $driver;
    }

    /** 解码后的配置数组（凭据字段在读取时解密）。 */
    public function config(): array
    {
        $raw = (string) ($this->attributes['config'] ?? '');
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $config = is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
        return \App\Core\CredentialCipher::openConfig($config);
    }

    /** 落库前封存凭据字段；已存在的明文配置在下次保存时自动封存。 */
    public function save(): bool
    {
        $raw = (string) ($this->attributes['config'] ?? '');
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $this->attributes['config'] = json_encode(
                        \App\Core\CredentialCipher::sealConfig($decoded),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
            } catch (\App\Core\CredentialCipherKeyUnavailable $e) {
                // 封存失败必须抛出：绝不能把明文凭据落库。
                throw $e;
            } catch (\Throwable) {
                // 仅配置 JSON 非法时走到这里，保持原值。
            }
        }
        $saved = parent::save();
        self::flushCache();
        return $saved;
    }

    public function isS3(): bool
    {
        return ($this->attributes['driver'] ?? 'local') === 's3';
    }

    public function isDefault(): bool
    {
        return (int) ($this->attributes['is_default'] ?? 0) === 1;
    }

    public function isEnabled(): bool
    {
        return (int) ($this->attributes['enabled'] ?? 1) === 1;
    }

    public function providerLabel(): string
    {
        return match ((string) ($this->attributes['provider'] ?? '')) {
            'cos' => '腾讯云 COS',
            'oss' => '阿里云 OSS',
            'aws' => 'AWS S3',
            'obs' => '华为云 OBS',
            'upyun' => '又拍云 USS',
            'qiniu' => '七牛云 Kodo',
            default => '',
        };
    }

    public function typeLabel(): string
    {
        if (!$this->isS3()) {
            return '本地存储';
        }
        $p = $this->providerLabel();
        return '对象存储' . ($p !== '' ? " · {$p}" : '');
    }

    public function instanceLabel(): string
    {
        $cfg = $this->config();
        if (!$this->isS3()) {
            return (string) ($cfg['path'] ?? \App\Storage\LocalDriver::defaultRelDir());
        }
        $region = (string) ($cfg['region'] ?? '');
        return (string) ($cfg['bucket'] ?? '') . ($region !== '' ? " ({$region})" : '');
    }

    public function isUsable(): bool
    {
        if (!$this->isS3()) {
            return true;
        }
        return self::missingCredentialFields(
            $this->config(),
            (string) ($this->attributes['provider'] ?? '')
        ) === [];
    }

    /**
     * 凭据完整性判定的唯一来源（模型 / 驱动 / 后台表单共用）。
     *
     * OBS 与又拍云不需要 region（OBS 改用 endpoint）。
     *
     * @param array<string,mixed> $cfg 明文配置（key/secret/bucket/region/endpoint）
     * @return list<string> 缺失字段标签；空数组 = 完整
     */
    public static function missingCredentialFields(array $cfg, string $provider): array
    {
        $missing = [];
        if (trim((string) ($cfg['key'] ?? '')) === '') $missing[] = 'AccessKey';
        if (trim((string) ($cfg['secret'] ?? '')) === '') $missing[] = 'SecretKey';
        if (trim((string) ($cfg['bucket'] ?? '')) === '') $missing[] = 'Bucket';
        if ($provider === 'obs') {
            if (trim((string) ($cfg['endpoint'] ?? '')) === '') $missing[] = 'Endpoint';
        } elseif ($provider !== 'upyun') {
            if (trim((string) ($cfg['region'] ?? '')) === '') $missing[] = 'Region';
        }
        return $missing;
    }

    /** @throws \RuntimeException provider SDK 缺失等。 */
    public function driver(): StorageInterface
    {
        if (!$this->isS3()) {
            $cfg = $this->config();
            return new \App\Storage\LocalDriver(
                (string) ($cfg['path'] ?? ''),
                (string) ($cfg['cdn'] ?? ''),
                max(1, (int) ($cfg['signed_ttl'] ?? 300))
            );
        }
        $driver = new \App\Storage\S3Driver();
        $driver->loadProfile($this);
        return $driver;
    }

    /**
     * 按迁移前的 storage / storage_provider 列推断实例归属。
     * 老数据没有 storage_profile_id，只能按驱动类型 + provider 归类；同类多实例时优先默认实例。
     */
    public static function resolveLegacy(string $storage, string $provider): ?self
    {
        $wantS3 = strtolower($storage) === 's3';
        $pool = [];
        foreach (self::all('sort_order ASC, id ASC') as $candidate) {
            if (!$candidate->isEnabled()) {
                continue;
            }
            if ($wantS3) {
                if (!$candidate->isS3() || (string) $candidate->provider !== $provider) {
                    continue;
                }
            } elseif ($candidate->isS3()) {
                continue;
            }
            $pool[] = $candidate;
        }
        if ($pool === []) {
            return null;
        }
        $default = self::defaultProfile();
        if ($default !== null) {
            foreach ($pool as $candidate) {
                if ((int) $candidate->id === (int) $default->id) {
                    return $candidate;
                }
            }
        }
        return $pool[0];
    }

    public static function defaultProfile(): ?self
    {
        if (self::$defaultResolved) {
            return self::$defaultCache;
        }
        self::$defaultResolved = true;

        $p = self::firstWhere('is_default', 1);
        if ($p !== null && $p->isEnabled()) {
            return self::$defaultCache = $p;
        }
        foreach (self::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isEnabled()) {
                return self::$defaultCache = $candidate;
            }
        }
        return self::$defaultCache = null;
    }

    /** 只认 storage_profile_id：猜同 provider 的其它实例会指向别的桶且无报错。 */
    public static function driverForImage(array $row): StorageInterface
    {
        $profileId = isset($row['storage_profile_id']) ? (int) $row['storage_profile_id'] : 0;
        if ($profileId <= 0) {
            throw new \RuntimeException(
                '图片记录缺少存储实例归属（storage_profile_id 为空，属迁移前历史数据）。'
                . '请运行 php cli/migrate-to-latest.php --apply 完成一次性迁移。'
            );
        }

        $profile = self::find($profileId);
        if ($profile === null) {
            throw new \RuntimeException(
                '图片记录指向的存储实例 #' . $profileId . ' 不存在。'
                . '请到「存储管理」恢复该实例，或运行 php cli/migrate-to-latest.php --apply 重新绑定。'
            );
        }
        return self::cachedDriver($profile);
    }

    /** 设为唯一默认实例并启用；两步更新包在事务里，避免留下「无默认实例」状态。 */
    public function setAsDefault(): bool
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $db->exec('UPDATE `storage_profiles` SET `is_default` = 0');
            $stmt = $db->prepare('UPDATE `storage_profiles` SET `is_default` = 1, `enabled` = 1 WHERE `id` = ?');
            $ok = $stmt->execute([(int) $this->attributes[static::$primaryKey]]);
            $ok ? $db->commit() : $db->rollBack();
            return $ok;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function enabledAll(): array
    {
        return array_values(array_filter(
            self::all('sort_order ASC, id ASC'),
            fn(self $p) => $p->isEnabled()
        ));
    }
}
