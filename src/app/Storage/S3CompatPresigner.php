<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * v1.3.1 迭代: S3 兼容预签名 PUT 签名器（纯 PHP，零 SDK 依赖）。
 *
 * 用途：对象存储前端直传（浏览器 → 云，绕过服务器流量）。生成限定
 * method=PUT、绑定 Content-Type、限时有效的 SigV4 预签名 URL。
 *
 * 兼容范围：AWS S3 / 腾讯云 COS / 阿里云 OSS / 华为 OBS 的 S3 兼容端点
 * （各家的 S3 兼容域名与其 SDK endpoint 同域，host 由调用方构造：
 * 虚拟主机式 {bucket}.{host}）。七牛 / 又拍云签名机制不同，不支持。
 *
 * 安全约束：
 *  - 只签 PUT；
 *  - Content-Type 进入 SignedHeaders（浏览器必须带相同头，防类型伪装）；
 *  - payload 约束用 UNSIGNED-PAYLOAD（浏览器无法预计算 SHA256），
 *    真实大小/存在性由 direct-confirm 端点 HeadObject 复核；
 *  - 有效期由调用方控制（默认 10 分钟）。
 */
final class S3CompatPresigner
{
    /**
     * @param string $host        已含 bucket 的最终主机（如 moerng-1300xxx.cos.ap-chengdu.myqcloud.com）
     * @param string $key         对象 key（服务端生成，如 2026/09/ab12cd34.png）
     * @param string $contentType 浏览器直传时必须携带的 Content-Type
     * @param int    $expires     签名有效期（秒）
     * @param string $region      SigV4 region（COS: ap-guangzhou；AWS: us-east-1；OSS: oss-cn-hangzhou…）
     * @return array{url: string, headers: array<string, string>}
     */
    public static function presignPut(
        string $host,
        string $key,
        string $contentType,
        int $expires,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        $host = rtrim($host, '/');
        $now = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $scope = "{$date}/{$region}/s3/aws4_request";

        // CanonicalURI：key 逐段 URI 编码（保留 /）
        $uri = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

        $credential = rawurlencode("{$accessKey}/{$scope}");
        $signedHeaders = 'content-type;host';
        $query = http_build_query([
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$accessKey}/{$scope}",
            'X-Amz-Date' => $now,
            'X-Amz-Expires' => (string) max(1, $expires),
            'X-Amz-SignedHeaders' => $signedHeaders,
        ], '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = "PUT\n{$uri}\n{$query}\n"
            . "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "\n{$signedHeaders}\nUNSIGNED-PAYLOAD";

        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return [
            'url' => "https://{$host}{$uri}?{$query}&X-Amz-Signature={$signature}",
            'headers' => ['Content-Type' => $contentType],
        ];
    }
}
