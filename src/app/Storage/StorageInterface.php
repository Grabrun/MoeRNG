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
