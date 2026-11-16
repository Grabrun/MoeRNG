<?php
declare(strict_types=1);

namespace App\Storage;

interface StorageInterface
{
    public function upload(string $localPath, string $remotePath, string $contentType): string;

    public function delete(string $remotePath): bool;

    public function url(string $remotePath): string;

    public function exists(string $remotePath): bool;

    /** 返回 null 表示大小未知，调用方不得当作 0。 */
    public function size(string $remotePath): ?int;
}
