<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;
use App\Storage\StorageInterface;

/**
 * One storage instance (v1.0.33): a named, independent storage configuration.
 *
 *   driver:   local | s3
 *   provider: cos | oss | aws | obs   (only when driver === 's3')
 *   config:   JSON — local: {path}; s3: {key, secret, region, bucket, endpoint, cdn}
 *
 * Multiple profiles can exist per storage type; one is marked is_default and
 * used for uploads unless the operator dynamically picks another at upload
 * time. Every image remembers the profile it was uploaded to.
 */
class StorageProfile extends Model
{
    protected static string $table = 'storage_profiles';
    protected static array $fillable = [
        'name', 'driver', 'provider', 'config', 'is_default', 'enabled', 'sort_order',
    ];

    /** Decoded config array (never null). Secret fields are transparently decrypted. */
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
        // v1.3.0-beta.2 (CVE-2026-MR-013): credentials are sealed at rest —
        // hand the caller plaintext values only.
        return \App\Core\CredentialCipher::openConfig($config);
    }

    /**
     * v1.3.0-beta.2 (CVE-2026-MR-013): seal credential fields before the row
     * hits the database. Pre-existing plaintext configs keep working (decrypt
     * is prefix-agnostic) and are re-sealed on their next save.
     */
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
            } catch (\Throwable) {
                // leave the raw value untouched — save() must stay best-effort
            }
        }
        return parent::save();
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

    /** Human-friendly type label: "本地存储" / "对象存储 · 腾讯云 COS". */
    public function typeLabel(): string
    {
        if (!$this->isS3()) {
            return '本地存储';
        }
        $p = $this->providerLabel();
        return '对象存储' . ($p !== '' ? " · {$p}" : '');
    }

    /** Short instance fingerprint shown in the table (bucket / local path). */
    public function instanceLabel(): string
    {
        $cfg = $this->config();
        if (!$this->isS3()) {
            return (string) ($cfg['path'] ?? 'public/uploads');
        }
        return (string) ($cfg['bucket'] ?? '') . ($cfg['region'] !== '' ? " ({$cfg['region']})" : '');
    }

    /** Whether the credentials are complete enough to actually use. */
    public function isUsable(): bool
    {
        if (!$this->isS3()) {
            return true;
        }
        $cfg = $this->config();
        if (!empty($cfg['key']) && !empty($cfg['secret']) && !empty($cfg['bucket'])) {
            // OBS signs against the endpoint (V2, no region needed); 又拍云 USS
            // 无 region（service=bucket, operator=key, password=secret）；
            // the other providers require a region.
            $provider = (string) ($this->attributes['provider'] ?? '');
            if ($provider === 'obs') {
                return !empty($cfg['endpoint']);
            }
            if ($provider === 'upyun') {
                return true;
            }
            return !empty($cfg['region']);
        }
        return false;
    }

    /**
     * The storage driver instance this profile resolves to.
     * @throws \RuntimeException when the provider SDK is missing etc.
     */
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

    /** The default profile (is_default=1); falls back to the first usable one. */
    public static function defaultProfile(): ?self
    {
        $p = self::firstWhere('is_default', 1);
        if ($p !== null && $p->isEnabled()) {
            return $p;
        }
        // No default (or it is disabled) — fall back to the first enabled one.
        foreach (self::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isEnabled()) {
                return $candidate;
            }
        }
        return null;
    }

    /** The default upload driver (used by Image::getStorageDriver). */
    public static function defaultDriver(): StorageInterface
    {
        $profile = self::defaultProfile();
        if ($profile !== null) {
            return $profile->driver();
        }
        // v1.0.35: profiles are the single source of truth — there is NO
        // settings fallback anymore. No usable profile means no storage.
        throw new \RuntimeException(
            '未配置任何启用的存储实例。请到后台「存储管理」新增存储实例并设为默认。'
        );
    }

    /** Upload driver for an image row, honouring the remembered profile. */
    public static function driverForImage(array $row): StorageInterface
    {
        $profileId = isset($row['storage_profile_id']) ? (int) $row['storage_profile_id'] : 0;
        if ($profileId > 0) {
            $profile = self::find($profileId);
            if ($profile !== null) {
                return $profile->driver();
            }
        }

        $type = (string) ($row['storage'] ?? 'local');
        if ($type !== 's3') {
            return new \App\Storage\LocalDriver();
        }

        // Legacy rows (pre-profile): match the remembered provider to an
        // enabled profile of the same provider, else fall back to the default.
        $provider = (string) ($row['storage_provider'] ?? '');
        foreach (self::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isS3() && $candidate->isEnabled()
                && ($candidate->attributes['provider'] ?? '') === $provider) {
                return $candidate->driver();
            }
        }
        $default = self::defaultProfile();
        if ($default !== null && $default->isS3()) {
            return $default->driver();
        }
        // v1.0.35: no settings fallback — profiles are the source of truth.
        throw new \RuntimeException(
            '图片记录无匹配的存储实例（profile_id 失效且无同 provider/默认 s3 实例）。'
            . '请检查「存储管理」配置。'
        );
    }

    /** Set this profile as the single default (clears others first). */
    public function setAsDefault(): bool
    {
        $db = Database::getInstance();
        $db->exec('UPDATE `storage_profiles` SET `is_default` = 0');
        $stmt = $db->prepare('UPDATE `storage_profiles` SET `is_default` = 1, `enabled` = 1 WHERE `id` = ?');
        return $stmt->execute([(int) $this->attributes[static::$primaryKey]]);
    }

    /** All enabled profiles, ordered — for the upload picker. */
    public static function enabledAll(): array
    {
        return array_values(array_filter(
            self::all('sort_order ASC, id ASC'),
            fn(self $p) => $p->isEnabled()
        ));
    }
}
