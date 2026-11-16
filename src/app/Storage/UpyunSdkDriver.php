<?php
declare(strict_types=1);

namespace App\Storage;

/** 又拍云 USS：service=bucket、operator=key、password=secret，没有 region。 */
class UpyunSdkDriver implements StorageInterface
{
    private string $service;
    private string $operator;
    private string $password;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private string $tokenKey = '';
    private int $signedTtl = 300;
    private ?\Upyun\Upyun $upyun = null;

    public function __construct(string $service, string $operator, string $password, string $cdnUrl = '', int $signedTtl = 300, string $sourceDomain = '', string $tokenKey = '')
    {
        $this->service = $service;
        $this->operator = $operator;
        $this->password = $password;
        $this->cdnUrl = rtrim($cdnUrl, '/');
        $this->sourceDomain = trim($sourceDomain);
        $this->signedTtl = max(1, $signedTtl);
        $this->tokenKey = $tokenKey;
    }

    public static function available(): bool
    {
        if (!extension_loaded('curl')) {
            return false;
        }
        return is_file(dirname(__DIR__, 2) . '/sdk/cos/vendor/autoload.php')
            && is_file(dirname(__DIR__, 2) . '/sdk/upyun/autoload.php');
    }

    private function client(): \Upyun\Upyun
    {
        if ($this->upyun !== null) {
            return $this->upyun;
        }
        require_once dirname(__DIR__, 2) . '/sdk/cos/vendor/autoload.php';
        require_once dirname(__DIR__, 2) . '/sdk/upyun/autoload.php';
        $this->upyun = new \Upyun\Upyun(new \Upyun\Config($this->service, $this->operator, $this->password));
        return $this->upyun;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("无法打开本地文件: {$localPath}");
        }
        try {
            $this->client()->write($remotePath, $handle, ['Content-Type' => $contentType]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        return $this->url($remotePath);
    }

    public function delete(string $remotePath): bool
    {
        try {
            $this->client()->delete($remotePath);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function exists(string $remotePath): bool
    {
        try {
            return $this->client()->has($remotePath);
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
            return $this->cdnUrl . '/' . rawurlencode($key);
        }
        // _upt = substr(md5(密钥 & etime & URI), 12, 8) . etime；未配 token_key 则返回裸 URL。
        $path = '/' . ltrim(str_replace('%2F', '/', rawurlencode($key)), '/');
        $host = $this->sourceDomain !== '' ? $this->sourceDomain : $this->service . '.b0.upaiyun.com';
        $base = 'https://' . $host . $path;
        if ($this->tokenKey === '') {
            return $base;
        }
        $expires = SignedUrl::alignedExpiry($this->signedTtl);
        $sign = substr(md5($this->tokenKey . '&' . $expires . '&' . $path), 12, 8) . $expires;
        return $base . '?_upt=' . $sign;
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
