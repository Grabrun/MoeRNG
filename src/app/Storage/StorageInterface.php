<?php
declare(strict_types=1);

namespace App\Storage;

interface StorageInterface
{
    /**
     * Upload a file to storage
     * @param string $localPath Local temporary file path
     * @param string $remotePath Remote storage path/key
     * @param string $contentType MIME type
     * @return string Public URL of the uploaded file
     */
    public function upload(string $localPath, string $remotePath, string $contentType): string;

    /**
     * Delete a file from storage
     * @param string $remotePath Remote storage path/key
     * @return bool
     */
    public function delete(string $remotePath): bool;

    /**
     * Get the public URL of a file
     * @param string $remotePath Remote storage path/key
     * @return string
     */
    public function url(string $remotePath): string;

    /**
     * Check if a file exists
     * @param string $remotePath Remote storage path/key
     * @return bool
     */
    public function exists(string $remotePath): bool;

    /**
     * v1.3.1 迭代: 对象元数据（HeadObject 语义）——直传登记时的服务端权威验证。
     * 返回 ['etag' => 内容MD5(小写hex, 去引号), 'size' => 字节数]，或 null（驱动
     * 无法提供）。S3 系简单 PUT 的 ETag 即内容 MD5 —— 由云生成、客户端不可伪造。
     */
    public function stat(string $remotePath): ?array;

    /**
     * v1.3.1 迭代: 前端直传签名（S3 兼容 SigV4 presigned PUT）。
     * 返回 null 表示该驱动不支持直传（local / 又拍云 / 七牛），上传回退
     * 服务器路径。返回 ['url' => ..., 'headers' => ['Content-Type' => ...]]。
     */
    public function presignPut(string $key, string $contentType, int $expires = 600): ?array;

    /**
     * Get driver configuration for display
     * @return array
     */
    public static function configFields(): array;

    /**
     * Get driver name
     * @return string
     */
    public static function name(): string;
}
