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
        'storage', 'storage_provider', 'storage_profile_id',
        'file_hash', 'file_sha256',
        'process_status', 'thumb_path', 'process_error'
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

    /**
     * v1.3.2-beta.2 迭代: 缩略图对外 URL —— 与 url() 同机制（经存储 driver
     * 动态生成，支持 CDN/预签名）。无缩略图（process_status 未完成或老图）
     * 返回空串，调用方应回退原图 url()。
     */
    public function thumbUrl(): string
    {
        $thumb = (string) ($this->attributes['thumb_path'] ?? '');
        if ($thumb === '') return '';
        try {
            $url = self::driverFor($this)->url($thumb);
            if ($url !== '') return $url;
        } catch (\Throwable) {
            // fall through
        }
        return '';
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
        // v1.3.1 性能优化: 用「COUNT + 随机 OFFSET」替代 ORDER BY RAND()——
        // 后者对过滤后的全量行做 filesort，图片量上万后延迟明显；两步法让
        // MySQL 走 idx_status/idx_rand 索引直接跳到目标行。随机分布与原实现
        // 等价（均匀抽样）。注意：随机上限必须是「符合条件的图片数」，而非
        // 分类 ID 数（外部审计报告初版在此处误用 count($ids)，已修正）。
        if ($categoryId !== null) {
            $ids = self::getCategoryAndChildIds($categoryId);
            if (empty($ids)) return null;

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::getInstance()->prepare(
                "SELECT COUNT(*) FROM images WHERE status = 'active' AND process_status = 'done' AND category_id IN ({$placeholders})"
            );
            $stmt->execute($ids);
            $total = (int) $stmt->fetchColumn();
            if ($total === 0) return null;

            $offset = random_int(0, $total - 1);
            $sql = "SELECT * FROM images WHERE status = 'active' AND process_status = 'done' AND category_id IN ({$placeholders}) LIMIT 1 OFFSET {$offset}";
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute($ids);
        } else {
            $stmt = Database::getInstance()->prepare(
                "SELECT COUNT(*) FROM images WHERE status = 'active' AND process_status = 'done'"
            );
            $stmt->execute();
            $total = (int) $stmt->fetchColumn();
            if ($total === 0) return null;

            $offset = random_int(0, $total - 1);
            $sql = "SELECT * FROM images WHERE status = 'active' AND process_status = 'done' LIMIT 1 OFFSET {$offset}";
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute();
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * v1.3.1 图库页: 取某分类（或未分类）下随机 N 张 active 图片。
     * 单分类行数量级小，ORDER BY RAND() LIMIT n 的 filesort 开销可忽略
     * （随机 API 的全表场景才值得用 COUNT+OFFSET 两步法）。
     * $categoryId === null 表示未分类（category_id IS NULL）。
     */
    public static function randomBatch(?int $categoryId, int $limit = 12): array
    {
        if ($categoryId === null) {
            $sql = "SELECT * FROM images WHERE status = 'active' AND process_status = 'done' AND category_id IS NULL ORDER BY RAND() LIMIT {$limit}";
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "SELECT * FROM images WHERE status = 'active' AND process_status = 'done' AND category_id = ? ORDER BY RAND() LIMIT {$limit}";
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute([$categoryId]);
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($row) => self::hydrate($row), $rows);
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
        $sql = "SELECT * FROM images WHERE status = 'active' AND process_status = 'done' AND category_id IN ({$placeholders}) ORDER BY sort_order ASC, id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($ids);

        return array_map(fn($row) => self::hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function countByCategory(int $categoryId): int
    {
        $ids = self::getCategoryAndChildIds($categoryId);
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT COUNT(*) FROM images WHERE status = 'active' AND process_status = 'done' AND category_id IN ({$placeholders})";
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
