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
     * v1.3.2 迭代: 计算远程对象的 SHA-256（内容哈希，十六进制）。
     * 用于分层校验的二次验证 —— MD5 初筛命中疑似重复时，用强哈希确定性判重。
     * 返回 null 表示无法读取（对象缺失/网络异常），调用方应保守放行（不误杀）。
     */
    public function hashFile(string $remotePath): ?string;



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
