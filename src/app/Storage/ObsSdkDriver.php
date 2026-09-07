<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * Huawei Cloud OBS via the OFFICIAL esdk-obs-php SDK (Obs\ObsClient).
 *
 * Fourth storage provider (after COS / OSS / AWS S3), added in v1.0.34-beta.1.
 * The SDK lives at <root>/sdk/obs/ (Obs/ + obs-autoloader.php copied from the
 * official package, examples stripped). Its runtime dependencies (Guzzle 7 /
 * PSR-7) are served by the single shared copy at <root>/sdk/cos/vendor/ —
 * load order matters: COS vendor autoloader MUST be required before the OBS
 * autoloader so both SDKs share one Guzzle/PSR instance.
 *
 * Configuration notes:
 *  - endpoint is REQUIRED (e.g. https://obs.cn-north-4.myhuaweicloud.com).
 *    Unlike COS/OSS/AWS there is no bucket+region host construction — OBS
 *    signs against the exact endpoint you give it.
 *  - signature defaults to OBS V2 when omitted (SDK's selectConstants('')
 *    resolves to V2), which needs no region. We leave it unset on purpose.
 *  - the SDK ships a hard monolog dependency in ObsLog, but logs are only
 *    initialized when ObsClient::initLog() is called — we never call it, so
 *    no monolog copy is needed.
 */
class ObsSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;
    private string $bucket;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private int $signedTtl = 300;
    private ?\Obs\ObsClient $client = null;

    public function __construct(
        string $accessKey,
        string $secretKey,
        string $endpoint,
        string $bucket,
        string $cdnUrl = '',
        int $signedTtl = 300,
        string $sourceDomain = ''
    ) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->endpoint  = $endpoint;
        $this->bucket    = $bucket;
        $this->cdnUrl    = $cdnUrl;
        $this->sourceDomain = trim($sourceDomain);
        $this->signedTtl = max(1, $signedTtl);
    }

    /**
     * Whether the official OBS SDK can be used on this deployment.
     * Requires the SDK autoloader + ObsClient + the shared COS vendor
     * (Guzzle/PSR) + simplexml/libxml.
     */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $root = dirname(__DIR__, 2);
        $ok = is_file($root . '/sdk/obs/obs-autoloader.php')
            && is_file($root . '/sdk/obs/Obs/ObsClient.php')
            && is_file($root . '/sdk/cos/vendor/autoload.php')
            && extension_loaded('simplexml')
            && extension_loaded('libxml');
        return $ok;
    }

    private function client(): \Obs\ObsClient
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $root = dirname(__DIR__, 2);
        // Dependency order is a hard rule: the one shared Guzzle/PSR copy
        // (COS vendor) must load before the OBS SDK autoloader.
        require_once $root . '/sdk/cos/vendor/autoload.php';
        require_once $root . '/sdk/obs/obs-autoloader.php';
        $this->client = new \Obs\ObsClient([
            'key'      => $this->accessKey,
            'secret'   => $this->secretKey,
            // v1.2.1: custom source domain (CNAME bound to the bucket) wins
            // over the region endpoint so presigned URLs use that domain.
            'endpoint' => $this->sourceDomain !== '' ? $this->sourceDomain : $this->endpoint,
            // signature left unset → OBS V2 (no region required)
        ]);
        return $this->client;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        // Stream the file body (the SDK accepts a resource for Body).
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open local file for upload: {$localPath}");
        }
        try {
            $this->client()->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => ltrim($remotePath, '/'),
                'Body'        => $stream,
                'ContentType' => $contentType,
            ]);
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException(
                '对象存储 (OBS) 上传失败: ' . $e->getMessage(),
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
        // OBS has no headObject — getObjectMetadata is the HEAD-object call.
        try {
            $this->client()->getObjectMetadata([
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
        // Private bucket by default — 7-day presigned GET.
        try {
            $model = $this->client()->createSignedUrl([
                'Method'  => 'GET',
                'Bucket'  => $this->bucket,
                'Key'     => ltrim($remotePath, '/'),
                'Expires' => $this->signedTtl, // seconds
            ]);
            return (string) ($model['SignedUrl'] ?? '');
        } catch (\Throwable) {
            // Presigning should not fail with valid credentials; fall back to a
            // bare endpoint URL so access errors surface on the image request.
            $host = $this->sourceDomain !== '' ? $this->sourceDomain : $this->endpoint;
            return rtrim($host, '/') . '/' . $this->bucket . '/' . ltrim($remotePath, '/');
        }
    }

    /**
     * Real connectivity test used by doctor.php — a live PUT probe.
     * Mirrors CosSdkDriver::testConnection(): actual upload + immediate
     * delete, because bucket-level checks need permissions image-bucket
     * users often lack (HeadBucket 403 ≠ broken deployment).
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
        return '对象存储 (华为云 OBS)';
    }
}
