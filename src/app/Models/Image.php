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
        'process_status', 'thumb_path', 'process_error', 'thumbs'
    ];

    /**
     * v1.3.2-beta.2 迭代: 多尺寸缩略图 —— 尺寸名 => 最大边像素（单一事实来源）。
     *
     * 存储 key 约定（md 保持历史路径以复用已生成的缩略图，不产生孤儿文件）：
     *   sm => thumbs/sm/{相对路径}.webp   （列表/网格用，320）
     *   md => thumbs/{相对路径}.webp      （卡片/预览用，640；= thumb_path 列）
     *   lg => thumbs/lg/{相对路径}.webp   （灯箱/大图用，1280）
     */
    public const THUMB_SIZES = ['sm' => 320, 'md' => 640, 'lg' => 1280];

    /** 默认尺寸（无参数调用的回退）。 */
    public const THUMB_DEFAULT = 'md';

    /**
     * v1.5.0-beta.1 存储结构统一（方案 A：资产为中心）—— 布局解析与键推导。
     *
     * 两种布局并存，**按 images.path 自身的形态判定**（无需额外标志列），
     * 因此读取端零改动（DB 里已存具体键），迁移工具也能对任意行推导目标键：
     *
     *   新（v2）：{yyyy}/{mm}/{uuid}/original.{ext}
     *            {yyyy}/{mm}/{uuid}/thumb-{sm|md|lg}.webp
     *   旧（v1）：{yyyy}/{mm}/{uuid}.{ext}                      ← 原图
     *            thumbs/{yyyy}/{mm}/{uuid}.webp                 ← md（无尺寸段）
     *            thumbs/{sm|lg}/{yyyy}/{mm}/{uuid}.webp
     *
     * 设计要点：一个资产的全部对象同处一个前缀（{yyyy}/{mm}/{uuid}/），
     * 删除/统计/迁移都成为前缀操作；档位命名统一，md 特例只保留在旧布局分支。
     */
    public const LAYOUT_V2 = 'v2';
    public const LAYOUT_V1 = 'v1';

    /**
     * 解析相对路径为资产各部分。
     *
     * @return array{layout: string, dir: string, ext: string, uuid: string}
     *   layout=v2/v1（v1 同时覆盖任何无法识别形态的历史路径）
     */
    public static function assetParts(string $path): array
    {
        $p = ltrim(str_replace('\\', '/', trim($path)), '/');

        // 新布局：{yyyy}/{mm}/{uuid}/original.{ext}
        if (preg_match('#^(\d{4})/(\d{2})/([0-9a-zA-Z]{8,64})/(original)\.([a-zA-Z0-9]+)$#', $p, $m)) {
            return [
                'layout' => self::LAYOUT_V2,
                'dir'    => $m[1] . '/' . $m[2] . '/' . $m[3],
                'ext'    => strtolower($m[5]),
                'uuid'   => $m[3],
            ];
        }

        // 旧布局：{yyyy}/{mm}/{uuid}.{ext}（含任何其它历史形态 → 一律按旧规则处理）
        $ext = (string) pathinfo($p, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($p, 0, -(strlen($ext) + 1)) : $p;
        return [
            'layout' => self::LAYOUT_V1,
            'dir'    => $base,
            'ext'    => strtolower($ext),
            'uuid'   => basename($base),
        ];
    }

    /** 是否已在新布局（迁移的判据）。 */
    public static function isNewLayout(string $path): bool
    {
        return self::assetParts($path)['layout'] === self::LAYOUT_V2;
    }

    /** 新布局下的原图相对路径（上传时调用；目录名用随机串，扩展名保留）。 */
    public static function newAssetPath(string $ext): string
    {
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'bin');
        return date('Y/m') . '/' . bin2hex(random_bytes(8)) . '/original.' . $ext;
    }

    /**
     * 由原图相对路径推导某尺寸缩略图的存储 key（与生成端共用同一约定）。
     *
     * v1.5.0: 按 path 布局自动选择规则 —— 新布局统一 `{dir}/thumb-{size}.webp`，
     * 旧布局保持历史规则不变（`md` 无尺寸段），二者互不干扰。
     */
    public static function thumbKey(string $size, string $path): string
    {
        $parts = self::assetParts($path);

        if ($parts['layout'] === self::LAYOUT_V2) {
            return $parts['dir'] . '/' . self::thumbVariant($size) . '.webp';
        }

        // —— 旧布局（历史规则，保持兼容，勿改）——
        $rel = ltrim((string) preg_replace('/\.[a-z0-9]+$/i', '.webp', $path), '/');
        if ($size === 'md') {
            return 'thumbs/' . $rel;
        }
        return 'thumbs/' . $size . '/' . $rel;
    }

    /** 缩略图文件名（不含扩展名）：thumb-{size}。 */
    public static function thumbVariant(string $size): string
    {
        return 'thumb-' . preg_replace('/[^a-z0-9]/', '', strtolower($size));
    }

    /** 解析 `thumbs` JSON 列为 尺寸 => key 映射（非法/空返回空数组）。 */
    public function thumbMap(): array
    {
        return self::decodeThumbMap(
            (string) ($this->attributes['thumbs'] ?? ''),
            (string) ($this->attributes['thumb_path'] ?? '')
        );
    }

    /**
     * 静态解析 thumbs JSON（迁移工具按行处理时需要，不必构造实例）。
     * md 兼容：老数据只有 thumb_path、thumbs 列里没有 md。
     */
    public static function decodeThumbMap(string $thumbsJson, string $thumbPath = ''): array
    {
        $map = $thumbsJson !== '' ? json_decode($thumbsJson, true) : null;
        if (!is_array($map)) {
            $map = [];
        }
        $out = [];
        foreach (self::THUMB_SIZES as $size => $_) {
            if (!empty($map[$size]) && is_string($map[$size])) {
                $out[$size] = $map[$size];
            }
        }
        if (!isset($out['md']) && $thumbPath !== '') {
            $out['md'] = $thumbPath;
        }
        return $out;
    }

    /**
     * 编码 尺寸 => key 映射为 `thumbs` 列值（JSON）。
     *
     * 始终写入 `ok:1` 标记 —— 这样「源图不可解码」或「源图小于所有档位」的
     * 行也会得到非空 JSON，回填队列据 `thumbs IS NULL OR thumbs=''` 选行时
     * 不会反复重选同一批行（否则前端进度循环永不收敛）。
     */
    public static function encodeThumbs(array $keys): string
    {
        $payload = ['ok' => 1];
        foreach ($keys as $size => $key) {
            if (isset(self::THUMB_SIZES[$size]) && is_string($key) && $key !== '') {
                $payload[$size] = $key;
            }
        }
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /** 某尺寸缩略图的存储 key（不存在返回空串）。 */
    public function thumbKeyFor(string $size): string
    {
        return $this->thumbMap()[$size] ?? '';
    }

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
     * v1.3.2-beta.2 迭代: 多尺寸缩略图对外 URL —— 与 url() 同机制（经存储
     * driver 动态生成，支持 CDN/预签名）。
     *
     * 无该尺寸缩略图（老数据/未处理）返回空串；调用方用 displayUrl() 取
     * 带回退链的可用地址。
     */
    public function thumbUrl(string $size = self::THUMB_DEFAULT): string
    {
        $key = $this->thumbKeyFor($size);
        if ($key === '') return '';
        try {
            $url = self::driverFor($this)->url($key);
            if ($url !== '') return $url;
        } catch (\Throwable) {
            // fall through
        }
        return '';
    }

    /**
     * 展示用图片地址（带回退链，永不返回空——除非该行彻底无路径）：
     *   请求尺寸缩略图 → md 缩略图 → 原图。
     * 视图直接用它，避免每处都写 `?:` 三元。
     */
    public function displayUrl(string $size = self::THUMB_DEFAULT): string
    {
        $u = $this->thumbUrl($size);
        if ($u !== '') return $u;
        if ($size !== self::THUMB_DEFAULT) {
            $u = $this->thumbUrl(self::THUMB_DEFAULT);
            if ($u !== '') return $u;
        }
        return $this->url();
    }

    /**
     * 各尺寸缩略图 URL 映射（仅含真实存在的尺寸；供 API / 前端按场景选用）。
     * 例：{"sm":"https://.../thumbs/sm/2026/09/x.webp","md":"...","lg":"..."}
     */
    public function thumbUrls(): array
    {
        $out = [];
        foreach (array_keys(self::THUMB_SIZES) as $size) {
            $u = $this->thumbUrl($size);
            if ($u !== '') $out[$size] = $u;
        }
        return $out;
    }

    /**
     * 构建 <img srcset="..."> 候选串（仅包含真实存在的缩略图）。
     * 少于两个候选时返回空串（无需 srcset）。
     */
    public function srcset(string $sizes = 'sm,md'): string
    {
        $parts = [];
        foreach (explode(',', $sizes) as $size) {
            $size = trim($size);
            if ($size === '' || !isset(self::THUMB_SIZES[$size])) continue;
            $u = $this->thumbUrl($size);
            if ($u === '') continue;
            $parts[] = $u . ' ' . self::THUMB_SIZES[$size] . 'w';
        }
        return count($parts) >= 2 ? implode(', ', $parts) : '';
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
     * $categoryId === null 表示未分类（category_id IS NULL）。
     *
     * v1.4.0-beta.2 性能修复: 弃用 `ORDER BY RAND()`。原注释假设"单分类行量级小、
     * filesort 可忽略"——但这正是图库页变慢的原因：图库页对**每个分类**调用一次，
     * MySQL 每次都要把该分类下符合条件的**全部行**物化后随机排序（还带 SELECT * 回表），
     * 10 个分类就是 10 次全量排序，图片量越大越慢。
     *
     * 改为与 random() 同源的「COUNT + 随机 OFFSET」两步法：
     *   1) COUNT(*) 走覆盖索引，极快；
     *   2) 随机取窗口起点后，按索引顺序一次取 N 行（无 ORDER BY → 无 filesort），
     *      扫描量 ≈ offset + N。
     * 取到的是"随机连续块"，用于图库展示与 ORDER BY RAND() 的观感等价，
     * 且随机性均匀（窗口起点在 [0, total-limit] 上均匀分布）。
     */
    public static function randomBatch(?int $categoryId, int $limit = 12): array
    {
        $limit = max(1, min(60, $limit));

        $where  = "`status` = 'active' AND `process_status` = 'done'";
        $params = [];
        if ($categoryId === null) {
            $where .= " AND `category_id` IS NULL";
        } else {
            $where .= " AND `category_id` = ?";
            $params[] = $categoryId;
        }

        $pdo = Database::getInstance();

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM `images` WHERE {$where}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
        if ($total === 0) {
            return [];
        }

        // 总量不超过一屏：直接全取（此时"随机"无意义，也避免无谓的 OFFSET 扫描）
        if ($total <= $limit) {
            $stmt = $pdo->prepare("SELECT * FROM `images` WHERE {$where} LIMIT {$limit}");
            $stmt->execute($params);
            return array_map(fn($row) => self::hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        // 随机窗口起点（上限取 total - limit，保证窗口不越界、一定取满）
        $offset = random_int(0, $total - $limit);
        $stmt = $pdo->prepare("SELECT * FROM `images` WHERE {$where} LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 防御：极端并发（COUNT 后行被删）导致取不满时，从头补齐
        if (count($rows) < $limit) {
            $fill = $limit - count($rows);
            $st2 = $pdo->prepare("SELECT * FROM `images` WHERE {$where} LIMIT {$fill}");
            $st2->execute($params);
            $rows = array_merge($rows, $st2->fetchAll(\PDO::FETCH_ASSOC));
        }

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
