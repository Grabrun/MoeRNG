<?php
declare(strict_types=1);

namespace App\Storage;

/** 华为云 OBS：按固定 endpoint 签名、不需要 region。 */
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
        // 依赖顺序：共享的 Guzzle 必须先于 OBS 自动加载，两个 SDK 才共用一份。
        require_once $root . '/sdk/cos/vendor/autoload.php';
        require_once $root . '/sdk/obs/obs-autoloader.php';
        $this->client = new \Obs\ObsClient([
            'key'      => $this->accessKey,
            'secret'   => $this->secretKey,
            // 自定义源站域名优先于 endpoint，预签名 URL 也用它。
            'endpoint' => $this->sourceDomain !== '' ? $this->sourceDomain : $this->endpoint,
            // 不设 signature，SDK 默认 OBS V2，无需 region。
            // 收紧连接与请求超时，避免端点卡死占住 FPM 进程。
            'connect_timeout' => 5,
            'socket_timeout'  => 30,
            'timeout'         => 30,
        ]);
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
            throw new \RuntimeException(
                '对象存储 (OBS) 上传失败：' . $e->getMessage(),
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
        // OBS 没有 headObject，getObjectMetadata 才是取对象元数据的调用。
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
        // bucket 默认私有，返回短时预签名 GET 链接。
        try {
            $model = $this->client()->createSignedUrl([
                'Method'  => 'GET',
                'Bucket'  => $this->bucket,
                'Key'     => ltrim($remotePath, '/'),
                'Expires' => $this->signedTtl, // seconds
            ]);
            return (string) ($model['SignedUrl'] ?? '');
        } catch (\Throwable $e) {
            // 预签名失败时，只有裸 URL 确认可匿名读才降级，否则抛错。
            $host = $this->sourceDomain !== '' ? $this->sourceDomain : $this->endpoint;
            $bare = rtrim($host, '/') . '/' . $this->bucket . '/' . ltrim($remotePath, '/');
            if (!\App\Storage\S3Driver::isPubliclyReadable($bare)) {
                error_log('[MoeRNG] OBS 预签名失败且裸 URL 不可匿名读（不降级）: ' . $e->getMessage());
                throw new \RuntimeException('OBS 预签名失败，且该对象无法匿名访问：' . $e->getMessage(), 0, $e);
            }
            error_log('[MoeRNG] OBS 预签名失败，降级为公开读裸 URL: ' . $e->getMessage());
            return $bare;
        }
    }

    // 用真实 PUT 探测：bucket 级检查常因权限 403，不能代表上传是否可用。
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
        }
        return $ok;
    }
}
