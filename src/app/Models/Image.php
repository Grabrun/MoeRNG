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
        'file_sha256',
        'process_status', 'thumb_path', 'process_error', 'thumbs',
        // 这两列必须留在 $fillable：hydrate 按 $fillable 过滤，漏写会被静默丢弃。
        'thumb_bytes',
        'processing_state',
    ];

    /** 尺寸名 => 最大边像素（单一事实来源）。 */
    public const THUMB_SIZES = ['sm' => 320, 'md' => 640, 'lg' => 1280];

    public const THUMB_DEFAULT = 'md';

    /** 「原图」标记：不在 thumbs 里，回退链必须先拦下它。 */
    public const SIZE_ORIGINAL = 'original';

    // 与 process_status 分工：后者是流水线状态且前台只出 done，本列不参与可见性过滤。
    public const ITEM_THUMB_META = 'thumb_meta';

    public const STATE_PENDING = 'pending';
    public const STATE_OK = 'ok';
    public const STATE_PARTIAL = 'partial';
    public const STATE_FAILED = 'failed';
    public const STATE_SKIPPED = 'skipped';

    /**
     * @return ?array{dir: string, ext: string, uuid: string} 无法识别返回 null（生成端抛错，不回退）。
     */
    public static function assetParts(string $path): ?array
    {
        $p = ltrim(str_replace('\\', '/', trim($path)), '/');

        if (preg_match('#^(\d{4})/(\d{2})/([0-9a-zA-Z]{8,64})/(original)\.([a-zA-Z0-9]+)$#', $p, $m)) {
            return [
                'dir'    => $m[1] . '/' . $m[2] . '/' . $m[3],
                'ext'    => strtolower($m[5]),
                'uuid'   => $m[3],
            ];
        }

        return null;
    }

    /**
     * 按 SHA-256 查图：仅用于 content_hashes 索引尚未覆盖到历史行时的列兜底。
     * 去重的正路是 ContentHash::find()，这里只在索引缺失时被调用一次并顺手补写索引。
     *
     * @param string[] $candidates
     */
    public static function findBySha256(array $candidates): ?static
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM `images` WHERE `file_sha256` = ? LIMIT 1');
        foreach ($candidates as $sha) {
            if (!is_string($sha) || $sha === '') {
                continue;
            }
            // 定长 64 hex，不存在 CHAR 尾空格问题，无需 TRIM 变体
            $stmt->execute([$sha]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                return static::hydrate($row);
            }
        }
        return null;
    }

    public static function newAssetPath(string $ext): string
    {
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'bin');
        return date('Y/m') . '/' . bin2hex(random_bytes(8)) . '/original.' . $ext;
    }

    /** 非 v2 布局无法推导缩略图键 —— 抛错暴露，不做旧规则回退。 */
    public static function thumbKey(string $size, string $path): string
    {
        $parts = self::assetParts($path);
        if ($parts === null) {
            throw new \RuntimeException(
                '无法按 v2 布局推导缩略图键（历史 v1 布局已不受支持）：' . $path
            );
        }
        return $parts['dir'] . '/' . self::thumbVariant($size) . '.webp';
    }

    public static function thumbVariant(string $size): string
    {
        return 'thumb-' . preg_replace('/[^a-z0-9]/', '', strtolower($size));
    }

    /**
     * 尺寸推导唯一来源，生成端与读取端共用；长边不超过档位则不放大。
     *
     * @return ?array{0: int, 1: int} 该档位不生成或尺寸未知时返回 null。
     */
    public static function thumbDimensions(int $width, int $height, int $target): ?array
    {
        if ($width <= 0 || $height <= 0 || $target <= 0) {
            return null;
        }
        $maxEdge = max($width, $height);
        if ($maxEdge <= $target) {
            return null;
        }
        $scale = $target / $maxEdge;
        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    public function thumbMap(): array
    {
        return self::decodeThumbMap(
            (string) ($this->attributes['thumbs'] ?? ''),
            (string) ($this->attributes['thumb_path'] ?? '')
        );
    }

    /** md 兼容：老数据只有 thumb_path，thumbs 里没有 md。 */
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

    /** 始终写入 ok:1，用于区分「本来无需处理」与「从未处理」。 */
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

    public function thumbKeyFor(string $size): string
    {
        return $this->thumbMap()[$size] ?? '';
    }

    /** 字节数只能在生成时实测（webp 编码结果随质量与 libwebp 版本变化）。 */
    public static function decodeThumbBytes(string $json): array
    {
        $map = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach (self::THUMB_SIZES as $size => $_) {
            if (isset($map[$size]) && is_int($map[$size]) && $map[$size] > 0) {
                $out[$size] = $map[$size];
            }
        }
        return $out;
    }

    public static function encodeThumbBytes(array $bytes): string
    {
        $payload = ['ok' => 1];
        foreach ($bytes as $size => $n) {
            if (isset(self::THUMB_SIZES[$size]) && is_int($n) && $n > 0) {
                $payload[$size] = $n;
            }
        }
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public static function decodeProcessingState(string $json): array
    {
        $map = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $item => $state) {
            if (is_string($item) && $item !== '' && is_string($state) && $state !== '') {
                $out[$item] = $state;
            }
        }
        return $out;
    }

    /** 合并写入：保留其它处理项的键。 */
    public static function withProcessingState(?string $json, string $item, string $state): string
    {
        $map = self::decodeProcessingState((string) $json);
        $map[$item] = $state;
        return (string) json_encode($map, JSON_UNESCAPED_SLASHES);
    }

    /** 由 thumbs/thumb_bytes 推导状态，上传与补全共用（唯一来源）。 */
    public static function deriveThumbMeta(?string $thumbsJson, string $thumbPath, ?string $bytesJson): string
    {
        $json = (string) $thumbsJson;
        if ($json === '') {
            return self::STATE_PENDING;
        }
        $keys = self::decodeThumbMap($json, $thumbPath);
        if ($keys === []) {
            return self::STATE_SKIPPED;
        }
        $bytes = self::decodeThumbBytes((string) $bytesJson);
        if (array_diff(array_keys($keys), array_keys($bytes)) === []) {
            return self::STATE_OK;
        }
        return $bytes !== [] ? self::STATE_PARTIAL : self::STATE_FAILED;
    }

    /** 待补全判据（唯一来源）：NULL/非法 JSON/缺键一律视作 pending。 */
    public static function thumbMetaPendingSql(): string
    {
        return self::thumbMetaExpr() . " = '" . self::STATE_PENDING . "'";
    }

    /** 可重试判据：只选 partial/failed，skipped 与 ok 重试无意义。 */
    public static function thumbMetaRetryableSql(): string
    {
        return self::thumbMetaExpr() . " IN ('" . self::STATE_PARTIAL . "', '" . self::STATE_FAILED . "')";
    }

    // 有生成列时必须返回裸列名，再套 COALESCE 会让优化器用不上索引。
    // 无生成列时退回 JSON 表达式：查询仍正确，只是慢。
    private static function thumbMetaExpr(): string
    {
        if (self::hasThumbMetaColumn()) {
            return '`thumb_meta`';
        }
        $path = '$.' . self::ITEM_THUMB_META;
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '{$path}')), '"
            . self::STATE_PENDING . "')";
    }

    private static function hasThumbMetaColumn(): bool
    {
        if (self::$thumbMetaColumn !== null) {
            return self::$thumbMetaColumn;
        }
        try {
            $stmt = Database::getInstance()->query("SHOW COLUMNS FROM `images` LIKE 'thumb_meta'");
            return self::$thumbMetaColumn = $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return self::$thumbMetaColumn = false;
        }
    }

    /** null = 未知（尚未生成或尚未补全），不是 0，也不可用原图字节顶替。 */
    public function thumbBytesFor(string $size): ?int
    {
        return self::decodeThumbBytes((string) ($this->attributes['thumb_bytes'] ?? ''))[$size] ?? null;
    }

    public function category(): ?Category
    {
        if (!$this->category_id) return null;
        return Category::find($this->category_id);
    }

    /** 生成失败返回空串而不抛：渲染不应因单行存储配置异常而 500。 */
    public function url(): string
    {
        return $this->driverUrlFor((string) ($this->attributes['path'] ?? ''));
    }

    /** @var array<string, string> 请求内 URL 记忆化，避免同一请求反复签名/预签名。 */
    private static array $urlMemo = [];

    private static ?bool $thumbMetaColumn = null;

    private function driverUrlFor(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $memoKey = (int) ($this->attributes['storage_profile_id'] ?? 0)
            . '|' . (string) ($this->attributes['storage'] ?? '')
            . '|' . $key;
        if (isset(self::$urlMemo[$memoKey])) {
            return self::$urlMemo[$memoKey];
        }
        try {
            $url = (string) self::driverFor($this)->url($key);
        } catch (\Throwable $e) {
            error_log('[MoeRNG] 图片 URL 生成失败（key=' . $key . '）: ' . $e->getMessage());
            $url = '';
        }
        return self::$urlMemo[$memoKey] = $url;
    }

    /** 无该尺寸缩略图时返回空串，调用方用 displayUrl() 取回退链。 */
    public function thumbUrl(string $size = self::THUMB_DEFAULT): string
    {
        return $this->driverUrlFor($this->thumbKeyFor($size));
    }

    /** 回退链：请求尺寸 → md → 原图。 */
    public function displayUrl(string $size = self::THUMB_DEFAULT): string
    {
        return $this->displayUrlWithSize($size)['url'];
    }

    /** @return array{url: string, size: ?string} size = null 表示已回退到原图。 */
    public function displayUrlWithSize(string $size = self::THUMB_DEFAULT): array
    {
        // original 不在 thumbs 里，不先拦下就会回退成 md 缩略图。
        if ($size === self::SIZE_ORIGINAL) {
            return ['url' => $this->url(), 'size' => self::SIZE_ORIGINAL];
        }

        $u = $this->thumbUrl($size);
        if ($u !== '') return ['url' => $u, 'size' => $size];
        if ($size !== self::THUMB_DEFAULT) {
            $u = $this->thumbUrl(self::THUMB_DEFAULT);
            if ($u !== '') return ['url' => $u, 'size' => self::THUMB_DEFAULT];
        }
        return ['url' => $this->url(), 'size' => null];
    }

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

    /**
     * 随机取一张。$device 为 pc / pe 时按构图过滤（pc=横图含方图，pe=竖图）；
     * 该方向没有图就去掉条件重取一次 —— <img src> 场景里 404 裂图比构图不合更糟。
     */
    public static function random(?int $categoryId = null, ?string $device = null): ?self
    {
        if ($categoryId !== null) {
            $ids = self::getCategoryAndChildIds($categoryId);
            if (empty($ids)) return null;

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where  = "status = 'active' AND process_status = 'done' AND category_id IN ({$placeholders})";
            $params = $ids;
        } else {
            $where  = "status = 'active' AND process_status = 'done'";
            $params = [];
        }

        $dir = self::orientationSql($device);

        $pdo = Database::getInstance();

        // 无 device 时只跑一轮，与旧行为完全一致；有 device 时第二轮是不带方向的兜底
        foreach ($dir === '' ? [''] : [$dir, ''] as $cond) {
            $scope = $where . $cond;
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM images WHERE {$scope}");
            $cnt->execute($params);
            $total = (int) $cnt->fetchColumn();
            if ($total === 0) continue;

            // COUNT 与 SELECT 非同一快照，OFFSET 可能落在存活集之外 → 重试后回退 LIMIT 1。
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $offset = random_int(0, $total - 1);
                $stmt = $pdo->prepare("SELECT * FROM images WHERE {$scope} LIMIT 1 OFFSET {$offset}");
                $stmt->execute($params);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) return self::hydrate($row);
            }

            $stmt = $pdo->prepare("SELECT * FROM images WHERE {$scope} LIMIT 1");
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) return self::hydrate($row);
        }

        return null;
    }

    /** 构图条件：pc=横图（含方图），pe=竖图，其余不过滤。与 random() 共用一处，避免两套口径。 */
    private static function orientationSql(?string $device): string
    {
        if ($device === 'pc') return ' AND `width` >= `height` AND `height` > 0';
        if ($device === 'pe') return ' AND `height` > `width`';
        return '';
    }

    /** 随机取某分类（null = 未分类）下的 N 张 active 图；COUNT + 随机窗口，避免 ORDER BY RAND() 的 filesort。 */
    public static function randomBatch(?int $categoryId, int $limit = 12, ?string $device = null): array
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
        $where .= self::orientationSql($device);

        $pdo = Database::getInstance();

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM `images` WHERE {$where}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
        if ($total === 0) {
            return [];
        }

        if ($total <= $limit) {
            $stmt = $pdo->prepare("SELECT * FROM `images` WHERE {$where} LIMIT {$limit}");
            $stmt->execute($params);
            return array_map(fn($row) => self::hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        $offset = random_int(0, $total - $limit);
        $stmt = $pdo->prepare("SELECT * FROM `images` WHERE {$where} LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 并发删行导致取不满时补齐；必须排除已拿到的 id，否则同一张图渲染两次。
        if (count($rows) < $limit) {
            $fill = $limit - count($rows);
            $taken = [];
            foreach ($rows as $takenRow) {
                if (isset($takenRow['id'])) $taken[] = (int) $takenRow['id'];
            }
            $fillWhere  = $where;
            $fillParams = $params;
            if ($taken !== []) {
                $ph = implode(',', array_fill(0, count($taken), '?'));
                $fillWhere  .= " AND `id` NOT IN ({$ph})";
                foreach ($taken as $tid) $fillParams[] = $tid;
            }
            $st2 = $pdo->prepare("SELECT * FROM `images` WHERE {$fillWhere} LIMIT {$fill}");
            $st2->execute($fillParams);
            $rows = array_merge($rows, $st2->fetchAll(\PDO::FETCH_ASSOC));
        }

        return array_map(fn($row) => self::hydrate($row), $rows);
    }

    /**
     * 图库分组：同一分类各取 $perSide 张横图与竖图。
     *
     * 先取 4 倍池子再按宽高分组，比两次独立随机少一次查库，也不会让同一张图在两栏各出现一次；
     * 某一侧抽样不足时（分类里竖图稀少）才定向补一次，补不齐就少给，不跨方向凑数。
     *
     * @return array{pc: array<int, self>, pe: array<int, self>}
     */
    public static function galleryBatch(?int $categoryId, int $perSide = 6): array
    {
        $perSide = max(1, min(12, $perSide));

        $sides = ['pc' => [], 'pe' => []];
        foreach (self::randomBatch($categoryId, $perSide * 4) as $img) {
            $sides[self::sideOf($img)][(int) $img->id] = $img;
        }

        foreach (['pc', 'pe'] as $side) {
            if (count($sides[$side]) >= $perSide) continue;
            foreach (self::randomBatch($categoryId, $perSide, $side) as $img) {
                if (count($sides[$side]) >= $perSide) break;
                $sides[$side][(int) $img->id] = $img;
            }
        }

        // 补抽可能带进另一侧已有的图；竖图更稀缺，保留竖图让横图让位。
        foreach (array_keys($sides['pc']) as $id) {
            if (isset($sides['pe'][$id])) unset($sides['pc'][$id]);
        }

        return [
            'pc' => array_slice(array_values($sides['pc']), 0, $perSide),
            'pe' => array_slice(array_values($sides['pe']), 0, $perSide),
        ];
    }

    /** 尺寸未知（历史行宽高为 0）归电脑端，保证图上得了墙而不是被藏起来。 */
    private static function sideOf(self $img): string
    {
        $w = (int) ($img->width ?? 0);
        $h = (int) ($img->height ?? 0);
        return ($h > 0 && $h > $w) ? 'pe' : 'pc';
    }

    /** @var array<int, array<int, int>>|null 请求级邻接表缓存。 */
    private static ?array $categoryChildrenMap = null;

    private static function categoryChildrenMap(): array
    {
        if (self::$categoryChildrenMap !== null) {
            return self::$categoryChildrenMap;
        }
        $stmt = Database::getInstance()->prepare("SELECT `id`, `parent_id` FROM `categories`");
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pid = (int) ($row['parent_id'] ?? 0);
            $cid = (int) $row['id'];
            if (!isset($map[$pid]) || !is_array($map[$pid])) $map[$pid] = [];
            $map[$pid][] = $cid;
        }
        return self::$categoryChildrenMap = $map;
    }

    /**
     * 含自身与全部后代的 id 集合（去重）；$seen 防脏数据里的 parent_id 环。
     *
     * @return array<int, int>
     */
    public static function getCategoryAndChildIds(int $categoryId): array
    {
        $map = self::categoryChildrenMap();

        $ids  = [];
        $seen = [];
        $queue = [$categoryId];
        while ($queue !== []) {
            $cur = array_shift($queue);
            if (isset($seen[$cur])) continue;
            $seen[$cur] = true;
            $ids[] = $cur;
            foreach (($map[$cur] ?? []) as $childId) {
                if (!isset($seen[$childId])) $queue[] = (int) $childId;
            }
        }

        return array_values(array_unique($ids));
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
        // 先删记录再删文件：反过来一旦 DB 删除失败，就会出现「文件没了、行还在」的坏记录。
        // 存储侧失败只留孤儿文件，记审计即可，不回滚记录。
        $deleted = parent::delete();
        if (!$deleted) {
            return false;
        }

        \App\Models\AuditLog::record('image_delete', [
            'id'       => (int) $this->attributes['id'],
            'filename' => (string) ($this->attributes['filename'] ?? ''),
            'path'     => (string) ($this->attributes['path'] ?? ''),
            'storage'  => (string) ($this->attributes['storage'] ?? ''),
        ]);

        $failed = [];
        try {
            $storage = self::driverFor($this);
            foreach ($this->allStorageKeys() as $key) {
                try {
                    if (!$storage->delete($key)) {
                        $failed[] = $key;
                    }
                } catch (\Throwable) {
                    $failed[] = $key;
                }
            }
        } catch (\Throwable $e) {
            // 存储实例缺失/配置损坏不应阻止记录删除。
            $failed = $this->allStorageKeys();
            \App\Models\AuditLog::record('image_delete_storage_failed', [
                'id'    => (int) $this->attributes['id'],
                'paths' => $failed,
                'error' => $e->getMessage(),
            ]);
            return true;
        }

        if (!empty($failed)) {
            \App\Models\AuditLog::record('image_delete_storage_failed', [
                'id'    => (int) $this->attributes['id'],
                'paths' => $failed,
            ]);
        }
        return true;
    }

    private function allStorageKeys(): array
    {
        $keys = [$this->path];
        foreach ($this->thumbMap() as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    public static function driverFor(self $img): \App\Storage\StorageInterface
    {
        return \App\Models\StorageProfile::driverForImage($img->attributes);
    }
}
