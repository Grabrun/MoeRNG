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
    private ?\Qcloud\Cos\Client $client = null;

    public function __construct(
        string $accessKey,
        string $secretKey,
        string $region,
        string $bucket,
        string $cdnUrl = ''
    ) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region    = $region;
        $this->bucket    = $bucket;
        $this->cdnUrl    = $cdnUrl;
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
        $this->client = new \Qcloud\Cos\Client([
            'region' => $this->region,
            'scheme' => 'https',
            'credentials' => [
                'secretId'  => $this->accessKey,
                'secretKey' => $this->secretKey,
            ],
        ]);
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

    public function url(string $remotePath): string
    {
        if ($this->cdnUrl !== '') {
            return rtrim($this->cdnUrl, '/') . '/' . ltrim($remotePath, '/');
        }
        // COS buckets are private by default — hand back a 7-day presigned GET.
        try {
            $signed = $this->client()->getPresignedUrl(
                'getObject',
                [
                    'Bucket' => $this->bucket,
                    'Key'    => ltrim($remotePath, '/'),
                ],
                '+7 days'
            );
            return (string) $signed;
        } catch (\Throwable) {
            // Presigning should not fail with valid credentials; fall back to a
            // bare public URL so access errors surface on the image request
            // rather than crashing the listing page.
            $host = "{$this->bucket}.cos.{$this->region}.myqcloud.com";
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
