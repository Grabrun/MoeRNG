<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * 七牛云 Kodo 官方 SDK 驱动（v1.1.1 迭代，集成第 6 家对象存储）。
 *
 * - sdk/qiniu/ = qiniu/php-sdk 官方源码（src/Qiniu/，自实现 curl，无 Guzzle 依赖）
 *   + 捆绑最小 MyCLabs\Enum\Enum（qiniu 的 composer 依赖，仅用 ::from）。
 * - 凭据模型：access_key + secret_key + bucket + region(z0/z1/z2/z3/as0/na0)。
 * - 下载 URL：CDN 优先；未配置时回退 {bucket}.qiniudns.com（建议配置自定义下载域名）。
 */
class QiniuSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $cdnUrl;

    public function __construct(string $accessKey, string $secretKey, string $bucket, string $region = 'z0', string $cdnUrl = '')
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->bucket = $bucket;
        $this->region = $region !== '' ? $region : 'z0';
        $this->cdnUrl = rtrim($cdnUrl, '/');
    }

    public static function available(): bool
    {
        return extension_loaded('curl')
            && is_file(dirname(__DIR__, 2) . '/sdk/qiniu/autoload.php');
    }

    private function auth(): \Qiniu\Auth
    {
        require_once dirname(__DIR__, 2) . '/sdk/qiniu/autoload.php';
        return new \Qiniu\Auth($this->accessKey, $this->secretKey);
    }

    private function config(): \Qiniu\Config
    {
        $cfg = new \Qiniu\Config($this->zone());
        $cfg->useHTTPS = true;
        return $cfg;
    }

    /** Map short region codes to the SDK Region factories. */
    private function zone(): \Qiniu\Region
    {
        return match ($this->region) {
            'z1' => \Qiniu\Region::regionHuabei(),
            'z2' => \Qiniu\Region::regionHuanan(),
            'z3' => \Qiniu\Region::regionHuadong2(),
            'as0' => \Qiniu\Region::regionSingapore(),
            'na0' => \Qiniu\Region::regionNorthAmerica(),
            default => \Qiniu\Region::regionHuadong(),
        };
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        $token = $this->auth()->uploadToken($this->bucket);
        $uploader = new \Qiniu\Storage\UploadManager($this->config());
        $ret = $uploader->putFile($token, $remotePath, $localPath, null, $contentType);
        if (isset($ret[0]['error'])) {
            throw new \RuntimeException('七牛上传失败: ' . (string) $ret[0]['error']);
        }
        return $this->url($remotePath);
    }

    public function delete(string $remotePath): bool
    {
        try {
            $bm = new \Qiniu\Storage\BucketManager($this->auth(), $this->config());
            $res = $bm->delete($this->bucket, $remotePath);
            $code = is_array($res) && isset($res[0]['code']) ? (int) $res[0]['code'] : (int) $res;
            return $code === 200 || $code === 204;
        } catch (\Throwable) {
            return false;
        }
    }

    public function exists(string $remotePath): bool
    {
        try {
            $bm = new \Qiniu\Storage\BucketManager($this->auth(), $this->config());
            $bm->stat($this->bucket, $remotePath);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function url(string $remotePath): string
    {
        $key = ltrim($remotePath, '/');
        if ($this->cdnUrl !== '') {
            return $this->cdnUrl . '/' . str_replace('%2F', '/', rawurlencode($key));
        }
        return 'https://' . $this->bucket . '.qiniudns.com/' . str_replace('%2F', '/', rawurlencode($key));
    }

    public function testConnection(): bool
    {
        $key = '_moerng_probe_' . bin2hex(random_bytes(4)) . '.txt';
        try {
            $this->upload(__FILE__, $key, 'text/plain');
            $ok = $this->exists($key);
            $this->delete($key);
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function configFields(): array
    {
        return [
            'key'    => ['label' => 'Access Key', 'type' => 'text', 'placeholder' => ''],
            'secret' => ['label' => 'Secret Key', 'type' => 'password', 'placeholder' => ''],
            'region' => ['label' => '区域 (Region)', 'type' => 'text', 'placeholder' => 'z0(华东) / z1(华北) / z2(华南) / z3(华东2) / as0(新加坡) / na0(北美)'],
            'bucket' => ['label' => '空间名 (Bucket)', 'type' => 'text', 'placeholder' => ''],
            'cdn'    => ['label' => 'CDN 下载域名（可选）', 'type' => 'text', 'placeholder' => 'https://cdn.example.com（建议配置，否则回退 qiniudns.com）'],
        ];
    }

    public static function name(): string
    {
        return '对象存储 · 七牛云 Kodo';
    }
}
