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
     * v1.5.0-beta.2: 实测远程对象的字节数。
     *
     * 用途：缩略图的字节数在生成时入库（`thumb_bytes` 列），**存量行**需要补全，
     * 而字节数无法从其它数据推导（取决于质量设置与 libwebp 版本）→ 只能实测。
     *
     * 返回 `null` 表示**未知**（对象缺失 / 网络异常 / 无法读取）。调用方必须按未知处理，
     * **绝不能当成 0**（否则接口会把「不知道」谎报成「空文件」）。
     */
    public function size(string $remotePath): ?int;



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
