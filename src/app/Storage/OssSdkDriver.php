<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * Alibaba Cloud OSS via the OFFICIAL alibabacloud/oss-v2 SDK (sdk/oss).
 *
 * Mirrors CosSdkDriver: every OSS operation is delegated to the official SDK
 * (v2, 0.4.x) instead of the built-in OSS4-HMAC-SHA256 signing in S3Driver.
 * The SDK's runtime dependencies (Guzzle 7, PSR-7, Promises, psr/http-message)
 * are served by sdk/cos/vendor, so no separate vendor tree is needed.
 *
 * If the SDK is missing, S3Driver falls back to the built-in signing.
 */
class OssSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $cdnUrl;
    private ?\AlibabaCloud\Oss\V2\Client $client = null;

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
     * Whether the OSS SDK can be used. Requires the bundled SDK source AND the
     * shared runtime dependencies (present when the COS SDK vendor exists) plus
     * the simplexml extension.
     */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $base = dirname(__DIR__, 2) . '/sdk';
        $ok = is_file($base . '/oss/src/Client.php')
            && is_file($base . '/cos/vendor/autoload.php')
            && extension_loaded('simplexml')
            && extension_loaded('libxml');
        return $ok;
    }

    private function client(): \AlibabaCloud\Oss\V2\Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $base = dirname(__DIR__, 2) . '/sdk';
        require_once $base . '/cos/vendor/autoload.php'; // Guzzle / PSR-7 / Promises
        require_once $base . '/oss/autoload.php';        // AlibabaCloud\Oss\V2\ -> src/

        $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
        $cfg->setCredentialsProvider(
            new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider($this->accessKey, $this->secretKey)
        );
        $cfg->setRegion($this->region);
        $this->client = new \AlibabaCloud\Oss\V2\Client($cfg);
        return $this->client;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        try {
            // putObjectFromFile streams the file (LazyOpenStream) and falls back
            // to guessing ContentType only when the request doesn't set it.
            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest($this->bucket, ltrim($remotePath, '/'));
            $request->contentType = $contentType;
            $this->client()->putObjectFromFile($request, $localPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException('对象存储 (OSS) 上传失败: ' . $e->getMessage(), 0, $e);
        }
        return $this->url($remotePath);
    }

    public function delete(string $remotePath): bool
    {
        try {
            $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($this->bucket, ltrim($remotePath, '/'));
            $this->client()->deleteObject($request);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function exists(string $remotePath): bool
    {
        try {
            $request = new \AlibabaCloud\Oss\V2\Models\HeadObjectRequest($this->bucket, ltrim($remotePath, '/'));
            $this->client()->headObject($request);
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
            // OSS buckets are private by default — hand back a 7-day presigned
            // GET so the image actually loads without exposing the key.
            $result = $this->client()->presign(
                new \AlibabaCloud\Oss\V2\Models\GetObjectRequest($this->bucket, ltrim($remotePath, '/')),
                ['expires' => \DateInterval::createFromDateString('7 days')]
            );
            return (string) ($result->url ?? '');
        } catch (\Throwable) {
            $host = "{$this->bucket}.oss-{$this->region}.aliyuncs.com";
            return "https://{$host}/" . ltrim($remotePath, '/');
        }
    }

    /**
     * Real connectivity test used by doctor.php — a live PUT probe.
     * NOT ListObjects/HeadBucket: bucket-level permission checks 403 for many
     * image-bucket users even though uploads work. An actual upload probe
     * mirrors the real operation; the probe object is deleted immediately.
     */
    public function testConnection(): bool
    {
        $key = 'doctor-probe-' . bin2hex(random_bytes(4)) . '.txt';
        $ok = false;
        try {
            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest($this->bucket, $key);
            $request->body = \AlibabaCloud\Oss\V2\Utils::streamFor('probe');
            $request->contentType = 'text/plain';
            $this->client()->putObject($request);
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }
        try {
            $del = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($this->bucket, $key);
            $this->client()->deleteObject($del);
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
        return '对象存储 (阿里云 OSS)';
    }
}
