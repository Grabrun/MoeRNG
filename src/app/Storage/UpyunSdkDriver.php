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
 */
class UpyunSdkDriver implements StorageInterface
{
    private string $service;
    private string $operator;
    private string $password;
    private string $cdnUrl;
    private string $sourceDomain = '';
    private int $signedTtl = 300;
    private ?\Upyun\Upyun $upyun = null;

    public function __construct(string $service, string $operator, string $password, string $cdnUrl = '', int $signedTtl = 300, string $sourceDomain = '')
    {
        $this->service = $service;
        $this->operator = $operator;
        $this->password = $password;
        $this->cdnUrl = rtrim($cdnUrl, '/');
        $this->sourceDomain = trim($sourceDomain);
        $this->signedTtl = max(1, $signedTtl);
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

    public function url(string $remotePath): string
    {
        $key = ltrim($remotePath, '/');
        if ($this->cdnUrl !== '') {
            return $this->cdnUrl . '/' . rawurlencode($key);
        }
        // v1.2.0 迭代: 又拍云 USS 防盗链 token 签名（_up_t = {expires}_{md5(operator:password:expires)}），
        // 私有空间短时链接，不经服务器代理。
        $expires = time() + $this->signedTtl;
        $sign = md5("{$this->operator}:{$this->password}:{$expires}");
        // v1.2.1: custom source domain (CNAME bound to the service) replaces
        // the default {service}.b0.upaiyun.com host for the signed URL.
        $host = $this->sourceDomain !== '' ? $this->sourceDomain : $this->service . '.b0.upaiyun.com';
        $base = 'https://' . $host . '/' . str_replace('%2F', '/', rawurlencode($key));
        return $base . '?_up_t=' . $expires . '_' . $sign;
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
            'service'  => ['label' => '服务名 (Service Name = Bucket)', 'type' => 'text', 'placeholder' => '如 mybucket'],
            'operator' => ['label' => '操作员名 (Operator)', 'type' => 'text', 'placeholder' => ''],
            'password' => ['label' => '操作员密码 (Password)', 'type' => 'password', 'placeholder' => ''],
            'cdn'      => ['label' => 'CDN 加速域名（可选）', 'type' => 'text', 'placeholder' => 'https://cdn.example.com'],
        ];
    }

    public static function name(): string
    {
        return '对象存储 · 又拍云 USS';
    }
}
