<?php
declare(strict_types=1);

namespace App\Storage;

class OssSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private int $signedTtl = 300;
    private ?\AlibabaCloud\Oss\V2\Client $client = null;

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
        require_once $base . '/cos/vendor/autoload.php';
        require_once $base . '/oss/autoload.php';

        $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
        $cfg->setCredentialsProvider(
            new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider($this->accessKey, $this->secretKey)
        );
        $cfg->setRegion($this->region);
        // 收紧连接与读写超时，避免端点卡死占住 FPM 进程。
        $cfg->setConnectTimeout(5.0);
        $cfg->setReadWriteTimeout(30.0);
        // 自定义源站域名走 CNAME，SDK 解析请求主机（含预签名）时优先用它。
        if ($this->sourceDomain !== '') {
            $cfg->setEndpoint($this->sourceDomain);
            $cfg->setUseCname(true);
        }
        $this->client = new \AlibabaCloud\Oss\V2\Client($cfg);
        return $this->client;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        try {
            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest($this->bucket, ltrim($remotePath, '/'));
            $request->contentType = $contentType;
            $this->client()->putObjectFromFile($request, $localPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException('对象存储 (OSS) 上传失败：' . $e->getMessage(), 0, $e);
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

    public function size(string $remotePath): ?int
    {
        try {
            return S3Driver::sizeUrl($this->url($remotePath));
        } catch (\Throwable) {
            return null;
        }
    }

    public function url(string $remotePath): string
    {
        if ($this->cdnUrl !== '') {
            return rtrim($this->cdnUrl, '/') . '/' . ltrim($remotePath, '/');
        }
        try {
            $result = $this->client()->presign(
                new \AlibabaCloud\Oss\V2\Models\GetObjectRequest($this->bucket, ltrim($remotePath, '/')),
                ['expires' => new \DateInterval('PT' . $this->signedTtl . 'S')]
            );
            return (string) ($result->url ?? '');
        } catch (\Throwable $e) {
            // 预签名失败时，只有裸 URL 确认可匿名读才降级，否则抛错。
            $host = $this->sourceDomain !== ''
                ? $this->sourceDomain
                : "{$this->bucket}.oss-{$this->region}.aliyuncs.com";
            $bare = "https://{$host}/" . ltrim($remotePath, '/');
            if (!\App\Storage\S3Driver::isPubliclyReadable($bare)) {
                error_log('[MoeRNG] OSS 预签名失败且裸 URL 不可匿名读（不降级）: ' . $e->getMessage());
                throw new \RuntimeException('OSS 预签名失败，且该对象无法匿名访问：' . $e->getMessage(), 0, $e);
            }
            error_log('[MoeRNG] OSS 预签名失败，降级为公开读裸 URL: ' . $e->getMessage());
            return $bare;
        }
    }

    // 用真实 PUT 探测：bucket 级检查常因权限 403，不能代表上传是否可用。
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
        }
        return $ok;
    }
}
