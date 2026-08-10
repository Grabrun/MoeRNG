<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * AWS S3 via the OFFICIAL AWS SDK for PHP (aws/aws-sdk-php, bundled in
 * sdk/aws/). This is the third and final SDK-backed driver — after v1.0.28 no
 * hand-rolled signing remains anywhere in the project.
 *
 * Runtime dependencies (Guzzle 7 / PSR-7 / Promises / psr/http-message) are
 * provided by sdk/cos/vendor; sdk/aws/ ships only Aws\ + JmesPath\ sources.
 * If the SDK is missing, S3Driver throws a clear error (no built-in fallback
 * anymore — the user asked for self-built signing to be removed).
 */
class AwsSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $endpoint;
    private string $cdnUrl;
    private ?\Aws\S3\S3Client $client = null;

    public function __construct(
        string $accessKey,
        string $secretKey,
        string $region,
        string $bucket,
        string $endpoint = '',
        string $cdnUrl = ''
    ) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region    = $region;
        $this->bucket    = $bucket;
        $this->endpoint  = $endpoint;
        $this->cdnUrl    = $cdnUrl;
    }

    public static function available(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $base = dirname(__DIR__, 2) . '/sdk';
        $ok = is_file($base . '/aws/Aws/S3/S3Client.php')
            && is_file($base . '/aws/Aws/functions.php')
            && is_file($base . '/cos/vendor/autoload.php')
            && extension_loaded('simplexml')
            && extension_loaded('libxml');
        return $ok;
    }

    private function client(): \Aws\S3\S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $base = dirname(__DIR__, 2) . '/sdk';
        require_once $base . '/cos/vendor/autoload.php'; // shared Guzzle / PSR-7
        require_once $base . '/aws/autoload.php';        // Aws\ + JmesPath\

        $args = [
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => $this->accessKey,
                'secret' => $this->secretKey,
            ],
        ];
        // Custom endpoint (S3-compatible gateways such as MinIO) — AWS S3
        // itself resolves the endpoint from region, so this stays empty there.
        if ($this->endpoint !== '') {
            $args['endpoint'] = rtrim($this->endpoint, '/');
            // Path-style for gateways that lack virtual-host bucket support.
            $args['use_path_style_endpoint'] = true;
        }
        $this->client = new \Aws\S3\S3Client($args);
        return $this->client;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
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
            throw new \RuntimeException('对象存储 (S3) 上传失败: ' . $e->getMessage(), 0, $e);
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
        try {
            $cmd = $this->client()->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
            ]);
            $request = $this->client()->createPresignedRequest($cmd, '+7 days');
            return (string) $request->getUri();
        } catch (\Throwable) {
            // Fall back to a bare public URL (works for public-read buckets).
            $host = $this->endpoint !== ''
                ? rtrim($this->endpoint, '/')
                : "{$this->bucket}.s3.{$this->region}.amazonaws.com";
            return $host . '/' . ltrim($remotePath, '/');
        }
    }

    /**
     * Real connectivity test used by doctor.php — a live PUT probe.
     * NOT headBucket: bucket-level permission checks 403 for many image-bucket
     * users even though uploads work. An actual upload probe mirrors the real
     * operation; the probe object is deleted immediately.
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
        return '对象存储 (AWS S3)';
    }
}
