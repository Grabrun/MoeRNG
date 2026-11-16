<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;

/**
 * Object-storage dispatcher for COS / OSS / AWS S3 / Huawei OBS.
 *
 * v1.0.28: every operation is delegated to the OFFICIAL cloud SDKs — all
 * hand-rolled signing code (COS V5, OSS4, AWS SigV4) has been REMOVED.
 * v1.0.34-beta.1: added Huawei Cloud OBS (sdk/obs/, ObsSdkDriver).
 *
 *   cos  -> qcloud/cos-sdk-v5      (sdk/cos/, CosSdkDriver)
 *   oss  -> alibabacloud/oss-v2    (sdk/oss/, OssSdkDriver)
 *   aws  -> aws/aws-sdk-php        (sdk/aws/, AwsSdkDriver)
 *   obs  -> esdk-obs-php           (sdk/obs/, ObsSdkDriver)
 *
 * If a provider's SDK is missing, a clear RuntimeException is thrown naming
 * the missing directory — there is intentionally NO built-in signing fallback
 * anymore. Shared runtime deps (Guzzle/PSR-7/Promises) live once in
 * sdk/cos/vendor and are reused by all four drivers.
 */
class S3Driver implements StorageInterface
{
    private string $provider;     // aws | obs | oss | cos | upyun | qiniu
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $endpoint;
    private string $cdnUrl;
    // v1.2.1: COS 自定义源站域名（绑到 bucket 的 CNAME）；签名 URL 会用它。
    private string $sourceDomain = '';
    // MR-04: 又拍云 CDN Token 防盗链独立密钥（仅 upyun 使用；空=不签名）。
    private string $tokenKey = '';
    private int $signedTtl = 300;

    /**
     * v1.0.35: profiles are the single source of truth. Constructing an
     * S3Driver loads the default *s3* profile (is_default first, else the
     * first enabled s3 instance). No settings fallback anymore.
     */
    public function __construct()
    {
        $profile = self::defaultS3Profile();
        if ($profile === null) {
            // Deferred failure: operations will throw with a clear message.
            $this->provider = '';
            return;
        }
        $this->loadProfile($profile);
    }

    /**
     * First enabled s3 profile (default first, else by sort order).
     */
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

    /**
     * v1.0.33: load credentials directly from a StorageProfile instance
     * (multi-instance storage config) instead of the legacy settings store.
     *
     * NOTE: use the public __get accessors (->provider), NEVER
     * $profile->attributes[...] — attributes is protected and the `??`
     * operator silently swallows the access error into the default value,
     * leaving provider empty ("未知存储服务商: "). This exact bug shipped
     * in v1.0.33 and made every profile-driven upload fail until v1.1.0-beta.3.
     */
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

    /**
     * MR-39: pure endpoint/sourceDomain sanity check. Returns warning strings;
     * empty array = looks safe.
     *
     * 调用点已前移到「保存存储实例」（StorageProfileController）—— 它是**配置写入
     * 边界**，每次保存校验一次即可。v2.0.0-beta.19 之前这里在 loadProfile() 里
     * 每次构造驱动都 error_log 一遍：合法的自建 MinIO（RFC1918）会导致每个请求
     * 都刷一条警告，日志噪音掩盖真告警。
     *
     * Rules:
     *   - only http:// / https:// schemes allowed (file://, php://, gopher:// … flagged);
     *   - a literal private/reserved IP (RFC1918, loopback, link-local, IPv6 ULA)
     *     is flagged as a possible internal SSRF target.
     * Hostnames are NOT DNS-resolved here (that would block per-request and could
     * fail); only literal-IP hosts are checked, so public CDN / custom CNAME domains
     * are never falsely flagged.
     *
     * @return string[]
     */
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
            $host = preg_replace('/^\[|\]$/', '', $host); // strip IPv6 [..]
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                continue; // hostname (CDN/CNAME) — not a literal IP, not flagged
            }
            // Valid IP: flagged only when it is private/reserved.
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $out[] = "{$label} host {$host} is a private/reserved IP; "
                    . 'confirm this is an intended self-hosted gateway, not an internal target.';
            }
        }
        return $out;
    }

    /** Missing SDK dir(s) named in the error so a partial deploy is obvious. */
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
        // 规则与 StorageProfile::isUsable() 同一份（v2.0.0-beta.19 之前各写一套，
        // 新增 provider 时极易只改一处）。
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

    /** 已解析的子驱动（请求内复用：构造会重建 SDK 客户端并解密凭据）。 */
    private ?StorageInterface $sdk = null;

    /**
     * 按 provider 解析子驱动 —— **唯一分发点**（v2.0.0-beta.19）。
     *
     * 原先每个公共方法各写一遍同一条 6 分支 `if (($sdk = $this->cosSdk()) !== null)`
     * 链（upload / delete / exists / url / testConnection 共 5 份）—— 新增一个
     * provider 要改 6 个工厂 + 5 条链。现在只有这一处。
     *
     * 每个分支先查 `available()`（SDK 目录是否齐全）：缺失时抛出指明目录的错误，
     * 而不是让 `new` 触发 "Class not found" 致命错误。
     */
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
            // 又拍云：service=bucket、operator=key、password=secret；MR-04 增补 token_key 防盗链密钥
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

    /**
     * 只取对象长度（存量缩略图补全用）。
     *
     * **刻意不委派给子驱动**：子驱动的 size() 走 `sizeUrl($this->url(...))`，
     * 不带 TLS 放行判断；自建网关（MinIO + 自签证书）会因此恒返回 null。
     * 这里必须传 tlsVerify()，故保留自有实现。
     */
    public function size(string $remotePath): ?int
    {
        try {
            return self::sizeUrl($this->url($remotePath), $this->tlsVerify());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * MR-21: decide whether to verify the endpoint's TLS certificate when
     * stream-reading a signed URL.
     *
     * Verification is ON unless the profile has an explicit custom `endpoint`
     * (a self-hosted S3-compatible gateway such as MinIO, which commonly uses a
     * self-signed cert). Official cloud domains (COS/OSS/AWS-official/OBS/
     * Upyun/Qiniu) leave `endpoint` empty and keep verification ON — restoring
     * the anti-MITM guarantee the signed URL is meant to provide.
     */
    private function tlsVerify(): bool
    {
        return $this->endpoint === '';
    }

    /**
     * 探测一个**未签名**的对象 URL 是否可以匿名读取（bucket 为公开读）。
     *
     * 用于 MR-42 的降级决策：预签名失败时不能无条件退回裸 URL ——
     * 私有 bucket 的裸 URL 必然 403，等于把「签名失效」变成「静默给失效链接」。
     * 只有确认该 URL 确实可匿名读到（2xx）才允许降级；否则应抛错让图片显式报错。
     *
     * 与 sizeUrl() 同样不能用 HEAD（预签名按方法签名，但这里是**裸** URL，
     * 公开读 bucket 的 GET/HEAD 行为一致）—— 一律用 GET + 读完立刻关。
     *
     * @return bool true=可匿名读取（可安全降级）；false=不可读/探测失败（应抛错）
     */
    public static function isPubliclyReadable(string $url, bool $tlsVerify = true): bool
    {
        $ctx = stream_context_create([
            // 读满 1 字节即足以判定 2xx；range 让 body 保持极小。
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
        // ignore_errors=true 时 4xx/5xx 也返回句柄，改用 $http_response_header
        // 里的首行状态码判定。
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
                $status = (int) $m[1]; // 跟随重定向时取最后一组
            }
        }
        @fclose($fp);
        return $status >= 200 && $status < 300;
    }

    /**
     * 只取对象长度：**读到响应头即关闭，不下载 body**。
     *
     * 为什么不用 HEAD：各家的预签名 URL 通常按 HTTP 方法签名，HEAD 会被拒（403）。
     * 所以用 GET 建立连接、从响应头里取 Content-Length 后立刻 fclose —— 省掉了
     * 整个 body，存量补全时每张图 3 档都能廉价实测。
     *
     * 跟随重定向时会有多组响应头，取**最后一组**的 Content-Length（即最终 body 的长度）。
     */
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

    /**
     * MR-43: hard ceiling on streamed download size (200 MB). A malicious or
     * runaway endpoint (see MR-39) trickling bytes could otherwise fill the
     * temp dir within the 120s stream timeout.
     */
    public const MAX_DOWNLOAD_BYTES = 209715200; // 200 * 1024 * 1024

    /**
     * v1.3.2 迭代: 把 url() 指向的对象流式下载到本地临时文件，返回临时文件路径。
     * 供回填脚本「先拉取到临时文件夹，再用 hash_file 算 MD5 + SHA-256」——
     * 保证对象存储回填时两个哈希都由同一份字节算出、不会混淆。失败返回 null。
     * @param string      $url            直读 URL（url() 签名 URL）
     * @param string|null $tmpDir         临时目录，默认 sys_get_temp_dir()
     * @param bool        $tlsVerify      MR-21: verify the peer TLS cert (default on;
     *                                    only off for an explicit self-hosted gateway).
     */
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
                // MR-43: cumulative byte ceiling — abort before the temp file can
                // grow unbounded against a trickle endpoint.
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

    /**
     * MR-21: build the stream ssl context. TLS certificate verification is ON by
     * default (the signed URL's anti-tamper is only meaningful if the bytes
     * actually come from the real endpoint). Verification is disabled ONLY when
     * the caller explicitly passes $tlsVerify=false — i.e. an admin-configured
     * self-hosted S3-compatible gateway (MinIO) that uses a self-signed cert.
     */
    private static function sslContext(bool $tlsVerify): array
    {
        if ($tlsVerify) {
            return ['verify_peer' => true, 'verify_peer_name' => true];
        }
        // Explicit self-hosted gateway with a self-signed cert — verification
        // off, but deliberately chosen, not the silent default.
        return ['verify_peer' => false, 'verify_peer_name' => false];
    }

    public function testConnection(): bool
    {
        // testConnection() 不在 StorageInterface 上（只有具体驱动有），故从 sdk()
        // 拿到实例后按具体方法调用 —— 六个子驱动的签名一致。
        /** @var StorageInterface $sdk */
        $sdk = $this->sdk();
        return $sdk->testConnection();
    }

    /**
     * Known object-storage providers (stored provider ids).
     */
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

    /** Field definitions rendered once per provider in the settings UI. */
    public static function providerFieldDefs(): array
    {
        return [
            'key'      => ['label' => 'Access Key（又拍云=操作员名）', 'type' => 'text', 'placeholder' => 'COS SecretId / OSS AccessKeyId / 又拍云操作员名'],
            'secret'   => ['label' => 'Secret Key（又拍云=操作员密码）', 'type' => 'password', 'placeholder' => ''],
            'region'   => ['label' => 'Region', 'type' => 'text', 'placeholder' => 'cos:ap-guangzhou / oss:cn-hangzhou / aws:us-east-1 / 七牛:z0 / OBS与又拍云可选'],
            'bucket'   => ['label' => 'Bucket（又拍云=服务名）', 'type' => 'text', 'placeholder' => 'COS 需带 APPID，如 mybucket-1250000000'],
            'endpoint' => ['label' => 'Endpoint（OBS 必填，其他可选）', 'type' => 'text', 'placeholder' => 'OBS: https://obs.cn-north-4.myhuaweicloud.com；COS: 域名后缀；AWS S3 兼容网关如 MinIO 填完整地址'],
            'cdn'      => ['label' => 'CDN 加速域名（可选）', 'type' => 'text', 'placeholder' => 'https://cdn.example.com'],
            // MR-04: 仅又拍云 Token 防盗链使用；其他服务商留空即可（后台表单按服务商显隐）。
            'token_key' => ['label' => 'Token 防盗链密钥（仅又拍云可选）', 'type' => 'password', 'placeholder' => '又拍云控制台「防盗链」密钥；留空=不签名'],
        ];
    }
}
