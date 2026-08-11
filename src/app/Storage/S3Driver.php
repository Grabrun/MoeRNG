<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;

/**
 * Object-storage dispatcher for COS / OSS / AWS S3 / Huawei OBS.
 *
 * v1.0.28: every operation is delegated to the OFFICIAL cloud SDKs — all
 * hand-rolled signing code (COS V5, OSS4, AWS SigV4) has been REMOVED.
 * v1.0.34-beta.1: added Huawei Cloud OBS (sdk/obs/, ObsSdkDriver).
 *
 *   cos  -> qcloud/cos-sdk-v5      (sdk/cos/, CosSdkDriver)
 *   oss  -> alibabacloud/oss-v2    (sdk/oss/, OssSdkDriver)
 *   aws  -> aws/aws-sdk-php        (sdk/aws/, AwsSdkDriver)
 *   obs  -> esdk-obs-php           (sdk/obs/, ObsSdkDriver)
 *
 * If a provider's SDK is missing, a clear RuntimeException is thrown naming
 * the missing directory — there is intentionally NO built-in signing fallback
 * anymore. Shared runtime deps (Guzzle/PSR-7/Promises) live once in
 * sdk/cos/vendor and are reused by all four drivers.
 */
class S3Driver implements StorageInterface
{
    private string $provider;     // aws | obs | oss | cos | upyun | qiniu
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $endpoint;
    private string $cdnUrl;

    /**
     * v1.0.35: profiles are the single source of truth. Constructing an
     * S3Driver loads the default *s3* profile (is_default first, else the
     * first enabled s3 instance). No settings fallback anymore.
     */
    public function __construct()
    {
        $profile = self::defaultS3Profile();
        if ($profile === null) {
            // Deferred failure: operations will throw with a clear message.
            $this->provider = '';
            return;
        }
        $this->loadProfile($profile);
    }

    /**
     * First enabled s3 profile (default first, else by sort order).
     */
    public static function defaultS3Profile(): ?\App\Models\StorageProfile
    {
        $default = \App\Models\StorageProfile::defaultProfile();
        if ($default !== null && $default->isS3() && $default->isUsable()) {
            return $default;
        }
        foreach (\App\Models\StorageProfile::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isS3() && $candidate->isEnabled() && $candidate->isUsable()) {
                return $candidate;
            }
        }
        return null;
    }

    public function setProvider(string $provider, ?array $creds = null): self
    {
        $this->provider = $provider;
        $data = $creds ?? [];
        $this->accessKey = (string) ($data['key'] ?? '');
        $this->secretKey = (string) ($data['secret'] ?? '');
        $this->region    = (string) ($data['region'] ?? '');
        $this->bucket    = (string) ($data['bucket'] ?? '');
        $this->endpoint  = (string) ($data['endpoint'] ?? '');
        $this->cdnUrl    = (string) ($data['cdn'] ?? '');
        return $this;
    }

    /**
     * v1.0.33: load credentials directly from a StorageProfile instance
     * (multi-instance storage config) instead of the legacy settings store.
     *
     * NOTE: use the public __get accessors (->provider), NEVER
     * $profile->attributes[...] — attributes is protected and the `??`
     * operator silently swallows the access error into the default value,
     * leaving provider empty ("未知存储服务商: "). This exact bug shipped
     * in v1.0.33 and made every profile-driven upload fail until v1.1.0-beta.3.
     */
    public function loadProfile(\App\Models\StorageProfile $profile): self
    {
        $cfg = $profile->config();
        $this->provider = (string) $profile->provider;
        $this->accessKey = (string) ($cfg['key'] ?? '');
        $this->secretKey = (string) ($cfg['secret'] ?? '');
        $this->region    = (string) ($cfg['region'] ?? '');
        $this->bucket    = (string) ($cfg['bucket'] ?? '');
        $this->endpoint  = (string) ($cfg['endpoint'] ?? '');
        $this->cdnUrl    = (string) ($cfg['cdn'] ?? '');
        return $this;
    }

    /**
     * Resolve credentials for $provider from the storage_profiles table
     * (v1.0.35: single source of truth — no settings JSON fallback).
     */
    private function loadProviderConfig(string $provider): array
    {
        foreach (\App\Models\StorageProfile::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isS3() && $candidate->isEnabled()
                && $candidate->provider === $provider) {
                $cfg = $candidate->config();
                $cfg['_profile_id'] = (int) $candidate->id;
                return $cfg;
            }
        }
        return [];
    }

    /** Missing SDK dir(s) named in the error so a partial deploy is obvious. */
    private function sdkMissing(string $provider): \RuntimeException
    {
        $dir = match ($provider) {
            'cos' => 'sdk/cos/',
            'oss' => 'sdk/oss/',
            'aws' => 'sdk/aws/',
            'obs' => 'sdk/obs/',
            'upyun' => 'sdk/upyun/',
            'qiniu' => 'sdk/qiniu/',
            default => 'sdk/',
        };
        return new \RuntimeException(
            "对象存储 [{$provider}] 的官方 SDK 未部署（缺少 {$dir} 目录）。"
            . '请完整解压 release 包后重启 PHP-FPM。'
        );
    }

    private function assertCredentials(): void
    {
        $missing = [];
        if ($this->accessKey === '') $missing[] = 'AccessKey';
        if ($this->secretKey === '') $missing[] = 'SecretKey';
        if ($this->bucket === '') $missing[] = 'Bucket';
        if ($this->provider === 'obs') {
            // OBS signs against the endpoint (V2 signature, no region needed),
            // so endpoint is the required field — region is optional.
            if ($this->endpoint === '') $missing[] = 'Endpoint';
        } elseif ($this->provider === 'upyun') {
            // 又拍云 USS：service(=bucket) + operator(=key) + password(=secret)，无 region。
        } elseif ($this->region === '') {
            $missing[] = 'Region';
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                "对象存储 [{$this->provider}] 凭据未完整配置（缺少 " . implode('/', $missing)
                . "）。请到后台「存储管理」填写对应服务商的凭据，并确认「默认上传服务商」指向已填写凭据的服务商。"
            );
        }
    }

    private function cosSdk(): ?CosSdkDriver
    {
        if ($this->provider !== 'cos') {
            return null;
        }
        if (!CosSdkDriver::available()) {
            throw $this->sdkMissing('cos');
        }
        return new CosSdkDriver($this->accessKey, $this->secretKey, $this->region, $this->bucket, $this->cdnUrl);
    }

    private function ossSdk(): ?OssSdkDriver
    {
        if ($this->provider !== 'oss') {
            return null;
        }
        if (!OssSdkDriver::available()) {
            throw $this->sdkMissing('oss');
        }
        return new OssSdkDriver($this->accessKey, $this->secretKey, $this->region, $this->bucket, $this->cdnUrl);
    }

    private function awsSdk(): ?AwsSdkDriver
    {
        if ($this->provider !== 'aws') {
            return null;
        }
        if (!AwsSdkDriver::available()) {
            throw $this->sdkMissing('aws');
        }
        return new AwsSdkDriver($this->accessKey, $this->secretKey, $this->region, $this->bucket, $this->endpoint, $this->cdnUrl);
    }

    private function obsSdk(): ?ObsSdkDriver
    {
        if ($this->provider !== 'obs') {
            return null;
        }
        if (!ObsSdkDriver::available()) {
            throw $this->sdkMissing('obs');
        }
        return new ObsSdkDriver($this->accessKey, $this->secretKey, $this->endpoint, $this->bucket, $this->cdnUrl);
    }

    private function upyunSdk(): ?UpyunSdkDriver
    {
        if ($this->provider !== 'upyun') {
            return null;
        }
        if (!UpyunSdkDriver::available()) {
            throw $this->sdkMissing('upyun');
        }
        // 又拍云：service=bucket、operator=key、password=secret
        return new UpyunSdkDriver($this->bucket, $this->accessKey, $this->secretKey, $this->cdnUrl);
    }

    private function qiniuSdk(): ?QiniuSdkDriver
    {
        if ($this->provider !== 'qiniu') {
            return null;
        }
        if (!QiniuSdkDriver::available()) {
            throw $this->sdkMissing('qiniu');
        }
        return new QiniuSdkDriver($this->accessKey, $this->secretKey, $this->bucket, $this->region, $this->cdnUrl);
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        $this->assertCredentials();

        if (($sdk = $this->cosSdk()) !== null) {
            return $sdk->upload($localPath, $remotePath, $contentType);
        }
        if (($sdk = $this->ossSdk()) !== null) {
            return $sdk->upload($localPath, $remotePath, $contentType);
        }
        if (($sdk = $this->awsSdk()) !== null) {
            return $sdk->upload($localPath, $remotePath, $contentType);
        }
        if (($sdk = $this->obsSdk()) !== null) {
            return $sdk->upload($localPath, $remotePath, $contentType);
        }
        if (($sdk = $this->upyunSdk()) !== null) {
            return $sdk->upload($localPath, $remotePath, $contentType);
        }
        if (($sdk = $this->qiniuSdk()) !== null) {
            return $sdk->upload($localPath, $remotePath, $contentType);
        }
        throw new \RuntimeException("未知存储服务商: {$this->provider}");
    }

    public function delete(string $remotePath): bool
    {
        if (($sdk = $this->cosSdk()) !== null) {
            return $sdk->delete($remotePath);
        }
        if (($sdk = $this->ossSdk()) !== null) {
            return $sdk->delete($remotePath);
        }
        if (($sdk = $this->awsSdk()) !== null) {
            return $sdk->delete($remotePath);
        }
        if (($sdk = $this->obsSdk()) !== null) {
            return $sdk->delete($remotePath);
        }
        if (($sdk = $this->upyunSdk()) !== null) {
            return $sdk->delete($remotePath);
        }
        if (($sdk = $this->qiniuSdk()) !== null) {
            return $sdk->delete($remotePath);
        }
        throw new \RuntimeException("未知存储服务商: {$this->provider}");
    }

    public function exists(string $remotePath): bool
    {
        if (($sdk = $this->cosSdk()) !== null) {
            return $sdk->exists($remotePath);
        }
        if (($sdk = $this->ossSdk()) !== null) {
            return $sdk->exists($remotePath);
        }
        if (($sdk = $this->awsSdk()) !== null) {
            return $sdk->exists($remotePath);
        }
        if (($sdk = $this->obsSdk()) !== null) {
            return $sdk->exists($remotePath);
        }
        if (($sdk = $this->upyunSdk()) !== null) {
            return $sdk->exists($remotePath);
        }
        if (($sdk = $this->qiniuSdk()) !== null) {
            return $sdk->exists($remotePath);
        }
        throw new \RuntimeException("未知存储服务商: {$this->provider}");
    }

    public function url(string $remotePath): string
    {
        if (($sdk = $this->cosSdk()) !== null) {
            return $sdk->url($remotePath);
        }
        if (($sdk = $this->ossSdk()) !== null) {
            return $sdk->url($remotePath);
        }
        if (($sdk = $this->awsSdk()) !== null) {
            return $sdk->url($remotePath);
        }
        if (($sdk = $this->obsSdk()) !== null) {
            return $sdk->url($remotePath);
        }
        if (($sdk = $this->upyunSdk()) !== null) {
            return $sdk->url($remotePath);
        }
        if (($sdk = $this->qiniuSdk()) !== null) {
            return $sdk->url($remotePath);
        }
        throw new \RuntimeException("未知存储服务商: {$this->provider}");
    }

    public function testConnection(): bool
    {
        if (($sdk = $this->cosSdk()) !== null) {
            return $sdk->testConnection();
        }
        if (($sdk = $this->ossSdk()) !== null) {
            return $sdk->testConnection();
        }
        if (($sdk = $this->awsSdk()) !== null) {
            return $sdk->testConnection();
        }
        if (($sdk = $this->obsSdk()) !== null) {
            return $sdk->testConnection();
        }
        if (($sdk = $this->upyunSdk()) !== null) {
            return $sdk->testConnection();
        }
        if (($sdk = $this->qiniuSdk()) !== null) {
            return $sdk->testConnection();
        }
        throw new \RuntimeException("未知存储服务商: {$this->provider}");
    }

    /**
     * Known object-storage providers (stored provider ids).
     */
    public static function providerList(): array
    {
        return [
            'cos' => '腾讯云 COS',
            'oss' => '阿里云 OSS',
            'aws' => 'AWS S3',
            'obs' => '华为云 OBS',
            'upyun' => '又拍云 USS',
            'qiniu' => '七牛云 Kodo',
        ];
    }

    /** Field definitions rendered once per provider in the settings UI. */
    public static function providerFieldDefs(): array
    {
        return [
            'key'      => ['label' => 'Access Key（又拍云=操作员名）', 'type' => 'text', 'placeholder' => 'COS SecretId / OSS AccessKeyId / 又拍云操作员名'],
            'secret'   => ['label' => 'Secret Key（又拍云=操作员密码）', 'type' => 'password', 'placeholder' => ''],
            'region'   => ['label' => 'Region', 'type' => 'text', 'placeholder' => 'cos:ap-guangzhou / oss:cn-hangzhou / aws:us-east-1 / 七牛:z0 / OBS与又拍云可选'],
            'bucket'   => ['label' => 'Bucket（又拍云=服务名）', 'type' => 'text', 'placeholder' => 'COS 需带 APPID，如 mybucket-1250000000'],
            'endpoint' => ['label' => 'Endpoint（OBS 必填，其他可选）', 'type' => 'text', 'placeholder' => 'OBS: https://obs.cn-north-4.myhuaweicloud.com；COS: 域名后缀；AWS S3 兼容网关如 MinIO 填完整地址'],
            'cdn'      => ['label' => 'CDN 加速域名（可选）', 'type' => 'text', 'placeholder' => 'https://cdn.example.com'],
        ];
    }

    /** Required by StorageInterface; returns the per-provider field defs. */
    public static function configFields(): array
    {
        return self::providerFieldDefs();
    }

    public static function name(): string
    {
        return '对象存储 (S3/OSS/COS)';
    }
}
