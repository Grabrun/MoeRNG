<?php
declare(strict_types=1);

namespace App\Storage;

class AwsSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $endpoint;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private int $signedTtl = 300;
    private ?\Aws\S3\S3Client $client = null;

    public function __construct(
        string $accessKey,
        string $secretKey,
        string $region,
        string $bucket,
        string $endpoint = '',
        string $cdnUrl = '',
        int $signedTtl = 300,
        string $sourceDomain = ''
    ) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region    = $region;
        $this->bucket    = $bucket;
        $this->endpoint  = $endpoint;
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
        require_once $base . '/cos/vendor/autoload.php';
        require_once $base . '/aws/autoload.php';

        $args = [
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => $this->accessKey,
                'secret' => $this->secretKey,
            ],
            // 收紧单请求超时，避免 TCP 卡死把 FPM 进程占住。
            'http'        => [
                'timeout'         => 30,
                'connect_timeout' => 5,
            ],
        ];
        // 自建网关填 endpoint；自定义源站域名优先，且保持 virtual-hosted 风格。
        $hostOverride = $this->sourceDomain !== '' ? $this->sourceDomain : $this->endpoint;
        if ($hostOverride !== '') {
            $args['endpoint'] = rtrim($hostOverride, '/');
            // 只有显式网关用 path-style；源站域名是 CNAME，不能开。
            $args['use_path_style_endpoint'] = $this->sourceDomain === '';
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
            throw new \RuntimeException('对象存储 (S3) 上传失败：' . $e->getMessage(), 0, $e);
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
        try {
            $cmd = $this->client()->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
            ]);
            $request = $this->client()->createPresignedRequest($cmd, '+' . $this->signedTtl . ' seconds');
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            // 预签名失败时，只有裸 URL 确认可匿名读才降级，否则抛错。
            $host = $this->sourceDomain !== ''
                ? rtrim($this->sourceDomain, '/')
                : ($this->endpoint !== ''
                    ? rtrim($this->endpoint, '/')
                    : "{$this->bucket}.s3.{$this->region}.amazonaws.com");
            $bare = $host . '/' . ltrim($remotePath, '/');
            if (!\App\Storage\S3Driver::isPubliclyReadable($bare)) {
                error_log('[MoeRNG] S3 预签名失败且裸 URL 不可匿名读（不降级）: ' . $e->getMessage());
                throw new \RuntimeException('S3 预签名失败，且该对象无法匿名访问：' . $e->getMessage(), 0, $e);
            }
            error_log('[MoeRNG] S3 预签名失败，降级为公开读裸 URL: ' . $e->getMessage());
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
