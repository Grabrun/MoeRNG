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

    /**
     * v1.5.0-beta.1 性能: 请求内实例行 / 驱动对象缓存。
     *
     * 读取路径（图库、API、srcset）为**每张图、每个尺寸**调用 driverForImage()，
     * 而它原先每次都 `find()` 查库并 `driver()` 新建驱动 —— 云端驱动构造要重建
     * SDK 客户端、并逐条解密凭据（AES-GCM），本地驱动也要做 realpath/mkdir 探测。
     * 一页 60 张图 × 2~3 个 URL ≈ 120~180 次查询 + 客户端构造，这是图库页慢的主因。
     *
     * 缓存是**请求级**的（PHP 每请求独立进程/清理），并在 save() 后清空 ——
     * 后台改完实例立刻回显新值，不会读到自己的旧数据。
     *
     * @var array<int, self|null>
     */
    private static array $rowCache = [];

    /** @var array<int, \App\Storage\StorageInterface> */
    private static array $driverCache = [];

    /** 无实例的裸本地驱动已随「按 provider 猜实例」的移除而删除（v2.0.0-beta.19）。 */

    /** 默认实例解析结果（null 表示"解析过但没有"）。 */
    private static ?self $defaultCache = null;
    private static bool $defaultResolved = false;

    /** 清空请求内缓存（save() 后自动调用；测试亦可用）。 */
    public static function flushCache(): void
    {
        self::$rowCache = [];
        self::$driverCache = [];
        self::$defaultCache = null;
        self::$defaultResolved = false;
    }

    /**
     * 「按 id 解析实例，缺失则回落到默认实例」—— 唯一实现。
     *
     * 控制器此前自建第三层 memo（ImageController::resolveProfile），而 find() 与
     * defaultProfile() 各自已有请求级缓存 —— 三层解决同一个问题。现在解析逻辑
     * 留在缓存所在的模型里，控制器直接调用。
     */
    public static function resolve(?int $profileId): ?self
    {
        if ($profileId !== null && $profileId > 0) {
            return self::find($profileId) ?? self::defaultProfile();
        }
        return self::defaultProfile();
    }

    /** 带请求内缓存的实例查找。 */
    public static function find(int|string $id): ?static
    {
        $key = (int) $id;
        if (array_key_exists($key, self::$rowCache)) {
            return self::$rowCache[$key];
        }
        return self::$rowCache[$key] = parent::find($id);
    }

    /**
     * 取（并缓存）实例的驱动对象 —— 构造代价高，同一请求内复用。
     *
     * public：队列处理（ImageController::processQueue / backfillThumbs）逐张图都要
     * 驱动。原先它们直接调 `$profile->driver()`，绕过了这层缓存 —— 每张图重建一次
     * 驱动：S3 系要逐字段解密凭据（每个 profile 2 个 secret 字段），本地系要
     * `realpath()` 两次。批 50 张就是 50 次无谓的重建。
     */
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
            } catch (\App\Core\CredentialCipherKeyUnavailable $e) {
                // MR-11 fail-closed: a real credential could not be sealed.
                // Do NOT persist the row in plaintext — let the error bubble to
                // the controller, which returns it to the admin instead.
                throw $e;
            } catch (\Throwable) {
                // leave the raw value untouched — save() must stay best-effort
                // (only for malformed config JSON, never for credential sealing)
            }
        }
        $saved = parent::save();
        // v1.5.0-beta.1: 写入后清空请求内缓存 —— 后台改完实例立刻回显新值，
        // 而读取路径（图库/API）照旧享受请求内缓存。
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
            return (string) ($cfg['path'] ?? \App\Storage\LocalDriver::defaultRelDir());
        }
        $region = (string) ($cfg['region'] ?? '');
        return (string) ($cfg['bucket'] ?? '') . ($region !== '' ? " ({$region})" : '');
    }

    /** Whether the credentials are complete enough to actually use. */
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
     * 对象存储凭据完整性判定 —— **唯一来源**（模型 isUsable / 驱动 assertCredentials
     * / 后台表单校验共用同一份规则）。
     *
     * 各 provider 的必填项差异：
     *   - OBS 按 endpoint 签名（V2，无 region 概念）；
     *   - 又拍云 USS：service(=bucket) + operator(=key) + password(=secret)，无 region；
     *   - 其余（COS / OSS / AWS / 七牛）必须有 region。
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
        // v1.5.0-beta.1 性能: 一次请求内只解析一次（原先每个调用点各查一次库）。
        if (self::$defaultResolved) {
            return self::$defaultCache;
        }
        self::$defaultResolved = true;

        $p = self::firstWhere('is_default', 1);
        if ($p !== null && $p->isEnabled()) {
            return self::$defaultCache = $p;
        }
        // No default (or it is disabled) — fall back to the first enabled one.
        foreach (self::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isEnabled()) {
                return self::$defaultCache = $candidate;
            }
        }
        return self::$defaultCache = null;
    }

    /**
     * 上传驱动 for an image row —— **只认 storage_profile_id**（v2.0.0-beta.19）。
     *
     * 此前的兜底链是「profile_id → storage!=s3 就裸本地 → 按 storage_provider 找同
     * provider 的启用实例 → 默认 s3 实例」，那是为 profile 机制之前的历史行准备的
     * **运行时兼容层**。它有两个真实危害：
     *   ① 猜错比报错更糟：同 provider 有多个实例（两个 COS 桶）时会把图片指向另一个
     *      桶，表现为"图片 404 / 读到别人的图"，且没有任何报错；
     *   ② 让后来者以为"归属可以缺失"，于是新代码继续不写 profile_id。
     *
     * 历史行改由**一次性迁移**处理：`php src/cli/migrate-legacy-refs.php`（干跑优先）。
     * 未迁移的行在这里**明确报错并给出命令**，不做静默猜测。
     */
    public static function driverForImage(array $row): StorageInterface
    {
        $profileId = isset($row['storage_profile_id']) ? (int) $row['storage_profile_id'] : 0;
        if ($profileId <= 0) {
            throw new \RuntimeException(
                '图片记录缺少存储实例归属（storage_profile_id 为空，属迁移前历史数据）。'
                . '请运行 php src/cli/migrate-legacy-refs.php --apply 完成一次性迁移。'
            );
        }

        $profile = self::find($profileId);
        if ($profile === null) {
            throw new \RuntimeException(
                '图片记录指向的存储实例 #' . $profileId . ' 不存在。'
                . '请到「存储管理」恢复该实例，或运行 php src/cli/migrate-legacy-refs.php 重新绑定。'
            );
        }
        return self::cachedDriver($profile);
    }

    /** Set this profile as the single default (clears others first). */
    /**
     * 设为默认存储实例并启用。
     *
     * 包在事务里：两步 UPDATE 之间若进程崩溃，会留下「无默认实例」的状态，
     * 上传全线报错（defaultProfile() 有 fallback 兜底，但状态本身是错的）。
     */
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

    /** All enabled profiles, ordered — for the upload picker. */
    public static function enabledAll(): array
    {
        return array_values(array_filter(
            self::all('sort_order ASC, id ASC'),
            fn(self $p) => $p->isEnabled()
        ));
    }
}
