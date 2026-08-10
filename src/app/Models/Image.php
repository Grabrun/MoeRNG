<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class Image extends Model
{
    protected static string $table = 'images';
    protected static array $fillable = [
        'filename', 'original_name', 'path', 'url', 'mime_type',
        'file_size', 'width', 'height', 'category_id', 'sort_order', 'status',
        'storage', 'storage_provider', 'storage_profile_id'
    ];

    public function category(): ?Category
    {
        if (!$this->category_id) return null;
        return Category::find($this->category_id);
    }

    /**
     * Public URL of the image.
     *
     * The URL is regenerated from `path` through the active storage driver on
     * every call instead of trusting the `url` column. Rows written while the
     * driver mis-resolved its base URL contain broken values like
     * "/2026/08/x.png" (missing the /public/uploads prefix); recomputing here
     * repairs the whole existing library without a data migration, and also
     * keeps URLs correct after switching driver or adding a CDN domain.
     */
    public function url(): string
    {
        $path = (string) ($this->attributes['path'] ?? '');
        if ($path !== '') {
            try {
                $url = self::driverFor($this)->url($path);
                if ($url !== '') return $url;
            } catch (\Throwable) {
                // fall through to the stored value
            }
        }
        return (string) ($this->attributes['url'] ?? '');
    }

    /** Absolute-ish stored URL as persisted in the DB (diagnostics only). */
    public function storedUrl(): string
    {
        return (string) ($this->attributes['url'] ?? '');
    }

    /** Whether the backing file actually exists in storage. */
    public function fileExists(): bool
    {
        $path = (string) ($this->attributes['path'] ?? '');
        if ($path === '') return false;
        try {
            return self::driverFor($this)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function random(?int $categoryId = null): ?self
    {
        if ($categoryId !== null) {
            // Get category and all sub-categories
            $ids = self::getCategoryAndChildIds($categoryId);
            if (empty($ids)) return null;

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT * FROM images WHERE status = 'active' AND category_id IN ({$placeholders}) ORDER BY RAND() LIMIT 1";
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute($ids);
        } else {
            $sql = "SELECT * FROM images WHERE status = 'active' ORDER BY RAND() LIMIT 1";
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute();
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    public static function getCategoryAndChildIds(int $categoryId, int $depth = 0): array
    {
        if ($depth > 20) return [];

        $ids = [$categoryId];
        // execute() returns bool; chaining fetchAll() onto it was a fatal error.
        $stmt = Database::getInstance()->prepare("SELECT `id` FROM `categories` WHERE `parent_id` = ?");
        $stmt->execute([$categoryId]);
        $children = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $ids = array_merge($ids, self::getCategoryAndChildIds((int) $childId, $depth + 1));
        }

        return array_values(array_unique($ids));
    }

    public static function getByCategory(int $categoryId, int $limit = 20, int $offset = 0): array
    {
        $ids = self::getCategoryAndChildIds($categoryId);
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM images WHERE status = 'active' AND category_id IN ({$placeholders}) ORDER BY sort_order ASC, id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($ids);

        return array_map(fn($row) => self::hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function countByCategory(int $categoryId): int
    {
        $ids = self::getCategoryAndChildIds($categoryId);
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT COUNT(*) FROM images WHERE status = 'active' AND category_id IN ({$placeholders})";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($ids);
        return (int) $stmt->fetchColumn();
    }

    public static function updateSortOrders(array $orderData): void
    {
        $sql = "UPDATE images SET sort_order = ? WHERE id = ?";
        $stmt = Database::getInstance()->prepare($sql);
        foreach ($orderData as $item) {
            $stmt->execute([$item['sort_order'], $item['id']]);
        }
    }

    public function delete(): bool
    {
        // Delete from the storage backend this image actually lives on.
        $storage = self::driverFor($this);
        try {
            $storage->delete($this->path);
        } catch (\Throwable) {
            // Storage deletion failure should not block DB deletion
        }
        return parent::delete();
    }

    public static function getStorageDriver(): \App\Storage\StorageInterface
    {
        static $driver = null;
        if ($driver !== null) return $driver;

        // v1.0.33: uploads resolve through the default storage profile; the
        // legacy settings-store path remains only when no profile exists yet.
        $driver = \App\Models\StorageProfile::defaultDriver();
        return $driver;
    }

    /**
     * Storage driver that actually holds THIS image.
     *
     * Each image remembers the backend it was uploaded to, so changing the
     * default storage never orphans previously-stored files. Since v1.0.33 the
     * remembered storage_profile_id wins (multiple COS/OSS/S3 instances are
     * supported); legacy rows fall back to provider matching against enabled
     * profiles, then to the global default.
     */
    public static function driverFor(self $img): \App\Storage\StorageInterface
    {
        return \App\Models\StorageProfile::driverForImage($img->attributes);
    }
}
