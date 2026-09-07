<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * Tencent Cloud COS via the OFFICIAL qcloud/cos-sdk-v5 (vendor/cos-sdk-v5).
 *
 * The built-in COS V5 signing in S3Driver is correct since v1.0.24 (verified
 * byte-for-byte against the server's expected StringToSign), but maintaining a
 * hand-rolled signature is a liability. This driver delegates every COS
 * operation to the official SDK, which is long-term maintained and covers
 * multipart upload, retries, and presigned URLs for us.
 *
 * SDK location: <root>/sdk/cos/  (src/ + vendor/ copied from the official
 * package). It is loaded lazily — only when a COS operation actually runs —
 * so requests that never touch object storage pay no autoload cost.
 *
 * If the SDK is missing (deployment without sdk/cos/), S3Driver silently
 * falls back to the built-in v1.0.24 signing, so a partial deploy still works.
 */
class CosSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private int $signedTtl = 300;
    private ?\Qcloud\Cos\Client $client = null;

    public function __construct(
        string $accessKey,
        string $secretKey,
        string $region,
        string $bucket,
        string $cdnUrl = '',
        int $signedTtl = 300,
        string $sourceDomain = ''
    ) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region    = $region;
        $this->bucket    = $bucket;
        $this->cdnUrl    = $cdnUrl;
        $this->sourceDomain = trim($sourceDomain);
        $this->signedTtl = max(1, $signedTtl);
    }

    /**
     * Whether the official SDK can be used on this deployment.
     * Cached per request; requires the copied vendor autoloader AND the PHP
     * extensions the SDK needs (simplexml / libxml are mandatory at runtime).
     */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $autoload = dirname(__DIR__, 2) . '/sdk/cos/vendor/autoload.php';
        $ok = is_file($autoload)
            && extension_loaded('simplexml')
            && extension_loaded('libxml');
        return $ok;
    }

    private function client(): \Qcloud\Cos\Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        require_once dirname(__DIR__, 2) . '/sdk/cos/vendor/autoload.php';
        // v1.2.1: when the operator has bound a custom source domain (e.g.
        // img.example.com → moerng-xxx.cos.ap-chengdu.myqcloud.com
        // in the COS console), pass it as the SDK `domain` so every request
        // host — including the presigned URL — is that custom domain.
        $config = [
            'region' => $this->region,
            'scheme' => 'https',
            'credentials' => [
                'secretId'  => $this->accessKey,
                'secretKey' => $this->secretKey,
            ],
        ];
        if ($this->sourceDomain !== '') {
            $config['domain'] = $this->sourceDomain;
        }
        $this->client = new \Qcloud\Cos\Client($config);
        return $this->client;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        // Stream the file body instead of slurping it into memory. Client::upload()
        // routes small files to putObject and large ones to MultipartUpload
        // automatically — a plain putObject would cap out at the simple-upload
        // limit (5 GB per the COS docs), so this also covers big images.
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open local file for upload: {$localPath}");
        }
        try {
            $this->client()->upload(
                $this->bucket,
                ltrim($remotePath, '/'),
                $stream,
                ['ContentType' => $contentType]
            );
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException(
                '对象存储 (COS) 上传失败: ' . $e->getMessage(),
                0,
                $e
            );
        }
        if (is_resource($stream)) {
            fclose($stream);
        }
        return $this->url($remotePath);
    }

    public function delete(string $remotePath): bool
    {
        try {
            $this->client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function exists(string $remotePath): bool
    {
        try {
            $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * v1.3.1 迭代: 前端直传签名 —— 官方 SDK 原生 getPresignedUrl('putObject')。
     * 官方 sample getPresignedUrl.php 实证：method 换 putObject 即 PUT 直传签名，
     * 'Headers' 里的项进入签名（锁定 Content-Type，浏览器必须带相同头）。
     * SDK 未部署（sdk/cos/ 缺失）→ 返回 null，上传回退服务器路径。
     */
    public function presignPut(string $key, string $contentType, int $expires = 600): ?array
    {
        if (!self::available()) {
            return null;
        }
        try {
            $signed = $this->client()->getPresignedUrl(
                'putObject',
                [
                    'Bucket'  => $this->bucket,
                    'Key'     => ltrim($key, '/'),
                    'Headers' => ['Content-Type' => $contentType],
                ],
                '+' . $expires . ' seconds'
            );
            $url = (string) $signed;
            if ($url === '') {
                return null;
            }
            return ['url' => $url, 'headers' => ['Content-Type' => $contentType]];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * v1.3.1 迭代: HeadObject 元数据 —— ETag（简单 PUT 即内容 MD5）+ 大小，
     * 供直传登记的服务端权威验证。异常/无法解析返回 null。
     */
    public function stat(string $remotePath): ?array
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
            ]);
            $etag = (string) ($result['ETag'] ?? '');
            $size = (int) ($result['ContentLength'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
        if ($etag === '') {
            return null;
        }
            // ETag 规范化：去引号 + 小写；非 32 位 hex（multipart/SSE-KMS 等
            // 非「内容MD5」语义）一律视为无法验证，返回 null。
            $etag = strtolower(trim((string) $etag, '"'));
            if (!preg_match('#^[a-f0-9]{32}$#', $etag)) {
                return null;
            }
        return ['etag' => $etag, 'size' => $size];
    }

    public function url(string $remotePath): string
    {
        if ($this->cdnUrl !== '') {
            return rtrim($this->cdnUrl, '/') . '/' . ltrim($remotePath, '/');
        }
        // COS buckets are private by default — short-lived presigned GET.
        try {
            $signed = $this->client()->getPresignedUrl(
                'getObject',
                [
                    'Bucket' => $this->bucket,
                    'Key'    => ltrim($remotePath, '/'),
                ],
                '+' . $this->signedTtl . ' seconds'
            );
            return (string) $signed;
        } catch (\Throwable) {
            // Presigning should not fail with valid credentials; fall back to a
            // bare public URL so access errors surface on the image request
            // rather than crashing the listing page. Honour the custom
            // source domain if the operator bound one in the COS console.
            $host = $this->sourceDomain !== ''
                ? $this->sourceDomain
                : "{$this->bucket}.cos.{$this->region}.myqcloud.com";
            return "https://{$host}/" . ltrim($remotePath, '/');
        }
    }

    /**
     * Real connectivity test used by doctor.php — a live PUT probe.
     *
     * NOT doesBucketExist(): bucket-level checks (HeadBucket/ListBucket) need
     * permissions many image-bucket IAM users lack, so they 403 and report
     * FAIL even though uploads work fine. An actual upload probe mirrors the
     * real operation the operator cares about; the probe object is deleted
     * immediately so nothing is left behind.
     */
    public function testConnection(): bool
    {
        $key = 'doctor-probe-' . bin2hex(random_bytes(4)) . '.txt';
        $ok = false;
        try {
            $this->client()->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => $key,
                'Body'        => 'probe',
                'ContentType' => 'text/plain',
            ]);
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }
        // Always clean up the probe object (best effort).
        try {
            $this->client()->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (\Throwable) {
            // ignore
        }
        return $ok;
    }

    /** Required by StorageInterface; field defs are shared via S3Driver. */
    public static function configFields(): array
    {
        return S3Driver::providerFieldDefs();
    }

    public static function name(): string
    {
        return '对象存储 (腾讯云 COS)';
    }
}
