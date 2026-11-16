<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;

/** COS/OSS/AWS/OBS/又拍云/七牛的分发入口，每家都委派给官方 SDK。 */
class S3Driver implements StorageInterface
{
    private string $provider;     // aws|obs|oss|cos|upyun|qiniu
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $endpoint;
    private string $cdnUrl;
    // COS 自定义源站域名，签名 URL 会用它。
    private string $sourceDomain = '';
    // 又拍云 CDN Token 防盗链独立密钥；空=不签名。
    private string $tokenKey = '';
    private int $signedTtl = 300;

    public function __construct()
    {
        $profile = self::defaultS3Profile();
        if ($profile === null) {
            // 缺配置时不在此抛错，留给实际操作报出具名错误。
            $this->provider = '';
            return;
        }
        $this->loadProfile($profile);
    }

    public static function defaultS3Profile(): ?\App\Models\StorageProfile
    {
        $default = \App\Models\StorageProfile::defaultProfile();
        if ($default !== null && $default->isS3() && $default->isUsable()) {
            return $default;
        }
        foreach (\App\Models\StorageProfile::all('sort_order ASC, id ASC') as $candidate) {
            if ($candidate->isS3() && $candidate->isEnabled() && $candidate->isUsable()) {
                return $candidate;
            }
        }
        return null;
    }

    // 必须用 __get 访问器读属性；读 protected 的 attributes 会被 ?? 静默吞成空值。
    public function loadProfile(\App\Models\StorageProfile $profile): self
    {
        $cfg = $profile->config();
        $this->provider = (string) $profile->provider;
        $this->accessKey = (string) ($cfg['key'] ?? '');
        $this->secretKey = (string) ($cfg['secret'] ?? '');
        $this->region    = (string) ($cfg['region'] ?? '');
        $this->bucket    = (string) ($cfg['bucket'] ?? '');
        $this->endpoint  = (string) ($cfg['endpoint'] ?? '');
        $this->cdnUrl    = (string) ($cfg['cdn'] ?? '');
        $this->sourceDomain = (string) ($cfg['source_domain'] ?? '');
        $this->tokenKey  = (string) ($cfg['token_key'] ?? '');
        $this->signedTtl = max(1, (int) ($cfg['signed_ttl'] ?? 300));
        return $this;
    }

    /** 只校验 scheme 与字面 IP 的私网段，不解析域名（避免误报 CDN）。 */
    public static function endpointSafetyWarnings(string $endpoint, string $sourceDomain): array
    {
        $out = [];
        foreach (['endpoint' => $endpoint, 'sourceDomain' => $sourceDomain] as $label => $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $raw)) {
                $out[] = "{$label} must start with http:// or https:// (got: {$raw})";
                continue;
            }
            $host = (string) parse_url($raw, PHP_URL_HOST);
            if ($host === '') {
                continue;
            }
            $host = preg_replace('/^\[|\]$/', '', $host);
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $out[] = "{$label} host {$host} is a private/reserved IP; "
                    . 'confirm this is an intended self-hosted gateway, not an internal target.';
            }
        }
        return $out;
    }

    private function sdkMissing(string $provider): \RuntimeException
    {
        $dir = match ($provider) {
            'cos' => 'sdk/cos/',
            'oss' => 'sdk/oss/',
            'aws' => 'sdk/aws/',
            'obs' => 'sdk/obs/',
            'upyun' => 'sdk/upyun/',
            'qiniu' => 'sdk/qiniu/',
            default => 'sdk/',
        };
        return new \RuntimeException(
            "对象存储 [{$provider}] 的官方 SDK 未部署（缺少 {$dir} 目录）。"
            . '请完整解压 release 包后重启 PHP-FPM。'
        );
    }

    private function assertCredentials(): void
    {
        $missing = \App\Models\StorageProfile::missingCredentialFields([
            'key'      => $this->accessKey,
            'secret'   => $this->secretKey,
            'bucket'   => $this->bucket,
            'region'   => $this->region,
            'endpoint' => $this->endpoint,
        ], $this->provider);
        if ($missing !== []) {
            throw new \RuntimeException(
                "对象存储 [{$this->provider}] 凭据未完整配置（缺少 " . implode('/', $missing)
                . "）。请到后台「存储管理」填写对应服务商的凭据，并确认「默认上传服务商」指向已填写凭据的服务商。"
            );
        }
    }

    /** 缓存子驱动实例：构造会重建 SDK 客户端并解密凭据。 */
    private ?StorageInterface $sdk = null;

    private function sdk(): StorageInterface
    {
        if ($this->sdk !== null) {
            return $this->sdk;
        }
        return $this->sdk = match ($this->provider) {
            'cos'   => CosSdkDriver::available()
                ? new CosSdkDriver($this->accessKey, $this->secretKey, $this->region, $this->bucket, $this->cdnUrl, $this->signedTtl, $this->sourceDomain)
                : throw $this->sdkMissing('cos'),
            'oss'   => OssSdkDriver::available()
                ? new OssSdkDriver($this->accessKey, $this->secretKey, $this->region, $this->bucket, $this->cdnUrl, $this->signedTtl, $this->sourceDomain)
                : throw $this->sdkMissing('oss'),
            'aws'   => AwsSdkDriver::available()
                ? new AwsSdkDriver($this->accessKey, $this->secretKey, $this->region, $this->bucket, $this->endpoint, $this->cdnUrl, $this->signedTtl, $this->sourceDomain)
                : throw $this->sdkMissing('aws'),
            'obs'   => ObsSdkDriver::available()
                ? new ObsSdkDriver($this->accessKey, $this->secretKey, $this->endpoint, $this->bucket, $this->cdnUrl, $this->signedTtl, $this->sourceDomain)
                : throw $this->sdkMissing('obs'),
            // 又拍云：service=bucket、operator=key、password=secret。
            'upyun' => UpyunSdkDriver::available()
                ? new UpyunSdkDriver($this->bucket, $this->accessKey, $this->secretKey, $this->cdnUrl, $this->signedTtl, $this->sourceDomain, $this->tokenKey)
                : throw $this->sdkMissing('upyun'),
            'qiniu' => QiniuSdkDriver::available()
                ? new QiniuSdkDriver($this->accessKey, $this->secretKey, $this->bucket, $this->region, $this->cdnUrl, $this->signedTtl, $this->sourceDomain)
                : throw $this->sdkMissing('qiniu'),
            default => throw new \RuntimeException("未知存储服务商: {$this->provider}"),
        };
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        $this->assertCredentials();
        return $this->sdk()->upload($localPath, $remotePath, $contentType);
    }

    public function delete(string $remotePath): bool
    {
        return $this->sdk()->delete($remotePath);
    }

    public function exists(string $remotePath): bool
    {
        return $this->sdk()->exists($remotePath);
    }

    public function url(string $remotePath): string
    {
        return $this->sdk()->url($remotePath);
    }

    // 不委派子驱动：子驱动的 size() 不带 TLS 放行判断，自签证书网关会恒返回 null。
    public function size(string $remotePath): ?int
    {
        try {
            return self::sizeUrl($this->url($remotePath), $this->tlsVerify());
        } catch (\Throwable) {
            return null;
        }
    }

    // 仅显式配置了自建 endpoint（常自签证书）时才关闭证书校验。
    private function tlsVerify(): bool
    {
        return $this->endpoint === '';
    }

    // 预签名失败时，只有探测确认裸 URL 可匿名读（2xx）才允许降级，否则抛错。
    public static function isPubliclyReadable(string $url, bool $tlsVerify = true): bool
    {
        $ctx = stream_context_create([
            // Range 只需 1 字节即可判定 2xx，body 保持极小。
            'http' => [
                'timeout'         => 5,
                'follow_location' => 1,
                'method'          => 'GET',
                'ignore_errors'   => true,
                'header'          => "Range: bytes=0-0\r\n",
            ],
            'ssl'  => self::sslContext($tlsVerify),
        ]);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return false;
        }
        // ignore_errors=true 时 4xx/5xx 也返回句柄，只能从响应头首行取状态码。
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
                $status = (int) $m[1]; // 跟随重定向时取最后一组
            }
        }
        @fclose($fp);
        return $status >= 200 && $status < 300;
    }

    // 不用 HEAD：各家预签名按 HTTP 方法签名，HEAD 会被拒（403），故用 GET 读响应头后立即 fclose。
    // 跟随重定向时有多组响应头，取最后一组的 Content-Length（即最终 body 长度）。
    public static function sizeUrl(string $url, bool $tlsVerify = true): ?int
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => 20, 'follow_location' => 1, 'method' => 'GET'],
            'ssl'  => self::sslContext($tlsVerify),
        ]);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return null;
        }
        $len = null;
        foreach (($http_response_header ?? []) as $h) {
            if (stripos((string) $h, 'Content-Length:') === 0) {
                $len = (int) trim(substr((string) $h, strlen('Content-Length:')));
            }
        }
        @fclose($fp);
        return ($len !== null && $len > 0) ? $len : null;
    }

    /** 流式下载硬上限 200MB，防止被拖慢的端点把临时目录塞满。 */
    public const MAX_DOWNLOAD_BYTES = 209715200; // 200 * 1024 * 1024

    public static function downloadUrl(string $url, ?string $tmpDir = null, bool $tlsVerify = true): ?string
    {
        $tmpDir = $tmpDir ?? sys_get_temp_dir();
        $tmp = rtrim($tmpDir, '/\\') . '/moerng-dl-' . bin2hex(random_bytes(6));
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
            ],
            'ssl' => self::sslContext($tlsVerify),
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            return null;
        }
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            return null;
        }
        $total = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    break;
                }
                $total += strlen($chunk);
                if ($total > self::MAX_DOWNLOAD_BYTES) {
                    fclose($in);
                    fclose($out);
                    @unlink($tmp);
                    error_log('[MoeRNG] downloadUrl aborted: object exceeds '
                        . (self::MAX_DOWNLOAD_BYTES / 1048576) . ' MB ceiling ('
                        . $total . ' bytes read).');
                    return null;
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
            if (filesize($tmp) === 0) {
                @unlink($tmp);
                return null;
            }
            return $tmp;
        } catch (\Throwable) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            return null;
        }
    }

    // TLS 校验默认开启；仅自建自签网关由调用方显式传 false 才关闭。
    private static function sslContext(bool $tlsVerify): array
    {
        if ($tlsVerify) {
            return ['verify_peer' => true, 'verify_peer_name' => true];
        }
        return ['verify_peer' => false, 'verify_peer_name' => false];
    }

    public function testConnection(): bool
    {
        // testConnection 不在接口上，按具体驱动调用（六家签名一致）。
        $sdk = $this->sdk();
        return $sdk->testConnection();
    }

    public static function providerList(): array
    {
        return [
            'cos' => '腾讯云 COS',
            'oss' => '阿里云 OSS',
            'aws' => 'AWS S3',
            'obs' => '华为云 OBS',
            'upyun' => '又拍云 USS',
            'qiniu' => '七牛云 Kodo',
        ];
    }

    public static function providerFieldDefs(): array
    {
        return [
            'key'      => ['label' => 'Access Key（又拍云=操作员名）', 'type' => 'text', 'placeholder' => 'COS SecretId / OSS AccessKeyId / 又拍云操作员名'],
            'secret'   => ['label' => 'Secret Key（又拍云=操作员密码）', 'type' => 'password', 'placeholder' => ''],
            'region'   => ['label' => 'Region', 'type' => 'text', 'placeholder' => 'cos:ap-guangzhou / oss:cn-hangzhou / aws:us-east-1 / 七牛:z0 / OBS与又拍云可选'],
            'bucket'   => ['label' => 'Bucket（又拍云=服务名）', 'type' => 'text', 'placeholder' => 'COS 需带 APPID，如 mybucket-1250000000'],
            'endpoint' => ['label' => 'Endpoint（OBS 必填，其他可选）', 'type' => 'text', 'placeholder' => 'OBS: https://obs.cn-north-4.myhuaweicloud.com；COS: 域名后缀；AWS S3 兼容网关如 MinIO 填完整地址'],
            'cdn'      => ['label' => 'CDN 加速域名（可选）', 'type' => 'text', 'placeholder' => 'https://cdn.example.com'],
            'token_key' => ['label' => 'Token 防盗链密钥（仅又拍云可选）', 'type' => 'password', 'placeholder' => '又拍云控制台「防盗链」密钥；留空=不签名'],
        ];
    }
}
