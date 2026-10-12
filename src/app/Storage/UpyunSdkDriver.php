<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * 又拍云 USS 官方 SDK 驱动（v1.1.1 迭代，集成第 5 家对象存储）。
 *
 * - sdk/upyun/ = upyun/sdk 官方源码（src/Upyun/），已做 psr7 v1→v2 适配补丁，
 *   运行时 Guzzle/psr7 由 sdk/cos/vendor（Guzzle 7.15.2 / psr7 v2）提供。
 * - 依赖顺序铁律：先 require sdk/cos/vendor/autoload.php，再 require sdk/upyun/autoload.php。
 * - 凭据模型：service_name（=bucket）+ operator（key）+ operator_password（secret），无 region。
 * - MR-04 修复：CDN Token 防盗链签名改用官方算法 `_upt`（独立 token_key，见 url()），
 *   不再复用操作员 API 密码，废弃旧的 `_up_t` 自研签名。
 */
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
        require_once dirname(__DIR__, 2) . '/sdk/cos/vendor/autoload.php'; // Guzzle/psr7 唯一来源
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

    public function hashFile(string $remotePath): ?string
    {
        // v1.3.2: 计算远程对象 SHA-256 —— 分层校验二次验证。
        // 复用 S3Driver::hashUrl() 经 url() 流式读取（低频路径）。失败返回 null。
        try {
            return S3Driver::hashUrl($this->url($remotePath));
        } catch (\Throwable) {
            return null;
        }
    }

    public function size(string $remotePath): ?int
    {
        // v1.5.0-beta.2: 存量缩略图的字节数补全用（只读响应头，不下载 body）。
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
        // MR-04 修复：又拍云 CDN Token 防盗链签名按官方规范重写。
        //   _upt = substr(md5(密钥 & etime & URI), 12, 8) . etime
        // 密钥取自又拍云控制台独立的「防盗链 Token 密钥」（config.token_key），
        // 不再复用操作员 API 密码；请求路径 /dir/xxx.ext 参与签名。
        // 未配置 token_key 时不签名，返回裸 URL（保持存量行为）。
        $path = '/' . ltrim(str_replace('%2F', '/', rawurlencode($key)), '/');
        // v1.2.1: custom source domain (CNAME bound to the service) replaces
        // the default {service}.b0.upaiyun.com host for the signed URL.
        $host = $this->sourceDomain !== '' ? $this->sourceDomain : $this->service . '.b0.upaiyun.com';
        $base = 'https://' . $host . $path;
        if ($this->tokenKey === '') {
            return $base;
        }
        $expires = time() + $this->signedTtl;
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
