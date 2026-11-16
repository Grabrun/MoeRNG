<?php
declare(strict_types=1);

namespace App\Storage;

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
        // 绑定自定义源站域名后，所有请求（含预签名 URL）都走该域名。
        $config = [
            'region' => $this->region,
            'scheme' => 'https',
            'credentials' => [
                'secretId'  => $this->accessKey,
                'secretKey' => $this->secretKey,
            ],
            // SDK 默认超时 3600s，收紧为 5s 连接 / 30s 请求。
            'timeout'         => 30,
            'connect_timeout' => 5,
        ];
        if ($this->sourceDomain !== '') {
            $config['domain'] = $this->sourceDomain;
        }
        $this->client = new \Qcloud\Cos\Client($config);
        return $this->client;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        // 用 Client::upload() 流式上传，大文件自动走分片。
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
            $signed = $this->client()->getPresignedUrl(
                'getObject',
                [
                    'Bucket' => $this->bucket,
                    'Key'    => ltrim($remotePath, '/'),
                ],
                '+' . $this->signedTtl . ' seconds'
            );
            return (string) $signed;
        } catch (\Throwable $e) {
            // 预签名失败时，只有裸 URL 确认可匿名读才降级，否则抛错。
            $host = $this->sourceDomain !== ''
                ? $this->sourceDomain
                : "{$this->bucket}.cos.{$this->region}.myqcloud.com";
            $bare = "https://{$host}/" . ltrim($remotePath, '/');
            if (!\App\Storage\S3Driver::isPubliclyReadable($bare)) {
                error_log('[MoeRNG] COS 预签名失败且裸 URL 不可匿名读（不降级）: ' . $e->getMessage());
                throw new \RuntimeException('COS 预签名失败，且该对象无法匿名访问: ' . $e->getMessage(), 0, $e);
            }
            error_log('[MoeRNG] COS 预签名失败，降级为公开读裸 URL: ' . $e->getMessage());
            return $bare;
        }
    }

    // 用真实 PUT 探测：bucket 级检查常因 IAM 权限 403，不能代表上传是否可用。
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
