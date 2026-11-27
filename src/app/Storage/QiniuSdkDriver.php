<?php
declare(strict_types=1);

namespace App\Storage;

/** 七牛 Kodo：官方 SDK 自带 curl 实现，不依赖 Guzzle。 */
class QiniuSdkDriver implements StorageInterface
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private int $signedTtl = 300;

    public function __construct(string $accessKey, string $secretKey, string $bucket, string $region = 'z0', string $cdnUrl = '', int $signedTtl = 300, string $sourceDomain = '')
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->bucket = $bucket;
        $this->region = $region !== '' ? $region : 'z0';
        $this->cdnUrl = rtrim($cdnUrl, '/');
        $this->sourceDomain = trim($sourceDomain);
        $this->signedTtl = max(1, $signedTtl);
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

    // SDK 默认不设 curl 超时，上传热路径显式给 5s 连接 / 30s 请求。
    private function reqOpt(): \Qiniu\Http\RequestOptions
    {
        return new \Qiniu\Http\RequestOptions(5, null, 30);
    }

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
        $uploader = new \Qiniu\Storage\UploadManager($this->config(), $this->reqOpt());
        $ret = $uploader->putFile($token, $remotePath, $localPath, null, $contentType);
        if (isset($ret[0]['error'])) {
            throw new \RuntimeException('七牛上传失败：' . (string) $ret[0]['error']);
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
        $key = ltrim($remotePath, '/');
        if ($this->cdnUrl !== '') {
            return $this->cdnUrl . '/' . str_replace('%2F', '/', rawurlencode($key));
        }
        // 私有空间短时下载签名：?e=过期&token=ak:urlsafe-b64(hmac-sha1)；自定义源站域名优先。
        $host = $this->sourceDomain !== '' ? $this->sourceDomain : $this->bucket . '.qiniudns.com';
        $base = 'https://' . $host . '/' . str_replace('%2F', '/', rawurlencode($key));
        $expires = SignedUrl::alignedExpiry($this->signedTtl);
        $signData = $base . '?e=' . $expires;
        $sign = rtrim(strtr(base64_encode(hash_hmac('sha1', $signData, $this->secretKey, true)), '+/', '-_'), '=');
        return $signData . '&token=' . $this->accessKey . ':' . $sign;
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
}
