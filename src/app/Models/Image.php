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
        'process_status', 'thumb_path', 'process_error', 'thumbs',
        // ★ v1.5.0-beta.2 修复：`thumb_bytes` **必须**在这里列出。
        //   Model::hydrate() → fill() 按 $fillable 过滤，漏写会让该列在读取时被**静默丢弃**
        //   （`thumbBytesFor()` 永远返回 null → API 的缩略图 file_size 永远为 null，
        //   而所有"源码里有这段"的断言都照样通过）。由 .dsh/model_fillable_audit.js 守着。
        'thumb_bytes',
        // v1.5.0-beta.3: 通用「图片处理状态」总控列（JSON，按处理项分键，如
        //   {"thumb_meta":"partial"}）。**同样必须列入 $fillable** —— 理由与上面的
        //   thumb_bytes 完全相同：hydrate 按 $fillable 过滤，漏写会让该列在读取时被
        //   **静默丢弃**（状态永远显示 pending）。改这行前先看 model_fillable_audit 怎么报。
        'processing_state',
    ];

    /**
     * v2.0.0-beta.1 迭代: 多尺寸缩略图 —— 尺寸名 => 最大边像素（单一事实来源）。
     *
     * 存储 key 约定（v2 资产中心布局，同一资产全部对象同处一个前缀）：
     *   sm => {dir}/thumb-sm.webp   （列表/网格用，320）
     *   md => {dir}/thumb-md.webp   （卡片/预览用，640；= thumb_path 列）
     *   lg => {dir}/thumb-lg.webp   （灯箱/大图用，1280）
     * （v1.5.0-beta.1 之前的 `thumbs/…` 旧树已随「取消兼容与迁移」整体移除。）
     */
    public const THUMB_SIZES = ['sm' => 320, 'md' => 640, 'lg' => 1280];

    /** 默认尺寸（无参数调用的回退）。 */
    public const THUMB_DEFAULT = 'md';

    /**
     * 「原图」标记 —— 与缩略图三档并列的合法 size 取值，但它**不是缩略图**。
     *
     * 定义在这里（而非只在 ApiController）是因为 displayUrlWithSize() 必须能识别它：
     * 它不是 thumbs 里的键，若当成普通档位走下去就会被"回退"到 md 缩略图 ——
     * 请求原图却拿到一张 640px 的图，且响应里 size 字段还会自称 md。
     */
    public const SIZE_ORIGINAL = 'original';

    /**
     * v1.5.0-beta.3: 通用「图片处理状态」—— 处理项名 + 状态取值。
     *
     * `images.processing_state` 是 JSON 列，**按处理项分键**：
     *     {"thumb_meta":"partial"}
     * 将来新增处理项（WebP 转换 / 布局迁移 / 哈希回填…）只需加一个键，不必再加列。
     * **键缺失 = 该项从未处理**（视作 pending）⇒ 存量行无需回填即可工作。
     *
     * ★ 与 `process_status` 的分工（必须分清，不要混用）：
     *   - `process_status`   —— 上传处理**流水线**状态（pending/processing/done/failed）。
     *                          它是 `ENUM` 且**前台只出 `done`** ⇒ 动它就等于动线上可见性；
     *   - `processing_state` —— 各处理项的**结果**状态，**不参与**任何可见性过滤。
     */
    public const ITEM_THUMB_META = 'thumb_meta';

    /** 处理项状态：尚未处理（键缺失时的默认值）。 */
    public const STATE_PENDING = 'pending';
    /** 处理项状态：完成且完整。 */
    public const STATE_OK = 'ok';
    /** 处理项状态：部分完成（例如只测到部分档位的字节数）。 */
    public const STATE_PARTIAL = 'partial';
    /** 处理项状态：该有结果却一个都没拿到 —— 可重试。 */
    public const STATE_FAILED = 'failed';
    /** 处理项状态：本来就不需要处理（例如源图小于所有档位、不可解码）。 */
    public const STATE_SKIPPED = 'skipped';

    /**
     * v2.0.0-beta.1 存储结构（资产中心布局，唯一方案）—— 布局解析与键推导。
     *
     * 按 `images.path` 自身的形态判定（无需标志列）：
     *
     *   唯一布局：{yyyy}/{mm}/{uuid}/original.{ext}
     *             {yyyy}/{mm}/{uuid}/thumb-{sm|md|lg}.webp
     *
     * 读取端不依赖本解析（`thumbs` JSON / `thumb_path` 列是权威登记），
     * 因此历史存量行仍可读取；解析仅用于**生成端键推导**（新上传必为 v2）。
     */
    /**
     * 解析相对路径为资产各部分。
     *
     * @return ?array{dir: string, ext: string, uuid: string}
     *   无法识别（历史 v1 布局等）返回 null —— 生成端遇 null 抛错，绝不静默回退。
     */
    public static function assetParts(string $path): ?array
    {
        $p = ltrim(str_replace('\\', '/', trim($path)), '/');

        // 唯一布局：{yyyy}/{mm}/{uuid}/original.{ext}
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
     * 按内容哈希找已存在的记录 —— **上传去重的唯一入口**。
     *
     * 参数可以给多组候选值，命中任意一个即算重复。上传路径会同时传「上传字节」
     * 与「落盘字节」两套哈希，原因见 ImageController::upload 的注释：
     * 原图会被转成 WebP，两类历史行各自只对得上其中一套。
     *
     * 顺序：先比 `file_sha256`（64 位定长），再比 `file_hash`（32 位 MD5，兼容还没
     * 回填强哈希的旧行）。
     *
     * 为什么需要 TRIM 兜底：`file_hash` 是 CHAR(64) 却只存 32 位 MD5。CHAR 会右补
     * 空格，而"比较时是否忽略尾空格"取决于列的排序规则（PAD SPACE / NO PAD）与
     * sql_mode 的 PAD_CHAR_TO_FULL_LENGTH —— 任一处对不上，`file_hash = ?` 就
     * **永远命中不了**：上传照常成功、界面上也没有任何异常，只是每次都被当成新图
     * （去重静默失效）。SHA-256 是定长 64，两边都对齐，所以拿它当主判据。
     *
     * 快路径走裸比较（能吃到索引）；只有 MD5 这一步未命中时才退到 TRIM（全表扫，
     * 但表通常只有千级行，且只发生在"疑似新图"时）。
     *
     * @param string[] $sha256Candidates 强哈希候选（去空）
     * @param string[] $md5Candidates    MD5 候选（去空）
     */
    public static function findByContentHash(array $sha256Candidates, array $md5Candidates): ?static
    {
        $db = Database::getInstance();
        $probe = static function (string $col, string $val, bool $allowTrim) use ($db): ?array {
            $stmt = $db->prepare("SELECT * FROM `images` WHERE `{$col}` = ? LIMIT 1");
            $stmt->execute([$val]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false && $allowTrim) {
                $stmt = $db->prepare("SELECT * FROM `images` WHERE TRIM(`{$col}`) = ? LIMIT 1");
                $stmt->execute([$val]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
            return $row === false ? null : $row;
        };

        // file_sha256 定长 64，无需 TRIM（真要补空格也是补满 64，比较仍成立）。
        foreach ($sha256Candidates as $sha) {
            if ($sha === '') continue;
            $row = $probe('file_sha256', $sha, false);
            if ($row !== null) {
                return static::hydrate($row);
            }
        }
        foreach ($md5Candidates as $md5) {
            if ($md5 === '') continue;
            $row = $probe('file_hash', $md5, true);
            if ($row !== null) {
                return static::hydrate($row);
            }
        }
        return null;
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
     * v2.0.0-beta.1: 唯一布局规则 `{dir}/thumb-{size}.webp`；非 v2 形态（历史
     * v1 布局）无法推导 —— 抛错暴露，不做旧规则回退。
     */
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

    /** 缩略图文件名（不含扩展名）：thumb-{size}。 */
    public static function thumbVariant(string $size): string
    {
        return 'thumb-' . preg_replace('/[^a-z0-9]/', '', strtolower($size));
    }

    /**
     * 缩略图尺寸推导 —— **唯一来源**，生成端（ImageController::makeThumbnails）
     * 与读取端（API 需要如实回报"返回的那张图"的宽高）共用。
     *
     * 规则（与生成时逐字一致）：原图长边超过目标档位时，按 `target / 长边` 等比缩放，
     * 宽高各自 `round()` 后至少 1px；长边本就不超过档位则**不放大**（该档不生成）。
     *
     * 为什么必须放在一处：两处各算一次就是两套规则，"接口报的宽高"与"实际生成的图"
     * 迟早对不上 —— 而这类偏差不会报错，只会让调用方按错误的尺寸预留布局。
     *
     * @return ?array{0: int, 1: int} 该档位不存在（不放大）或原图尺寸未知时返回 null。
     */
    public static function thumbDimensions(int $width, int $height, int $target): ?array
    {
        if ($width <= 0 || $height <= 0 || $target <= 0) {
            return null;
        }
        $maxEdge = max($width, $height);
        if ($maxEdge <= $target) {
            return null;   // 不放大 —— 该档位不会生成
        }
        $scale = $target / $maxEdge;
        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
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
     * 始终写入 `ok:1` 标记 —— 这样「源图不可解码」「源图小于所有档位」与
     * 「从未处理过」就能区分开（配合 `thumb_bytes` 可推导出 skipped / ok / partial / failed，
     * 见 deriveThumbMeta）。
     *
     * v1.5.0-beta.3：**选行判据已改为 `processing_state` 状态驱动**（thumbMetaPendingSql），
     * 不再直接看本列 —— 这样「对象长度读不到」的行也能离开队列、同时保留可重试语义。
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

    /**
     * 解析 `thumb_bytes` JSON 列为 尺寸 => 字节数（非法/缺失忽略）。
     *
     * v1.5.0-beta.2：缩略图的字节数**在生成时实测**并入库 —— 它无法从别的数据推导
     * （webp 编码结果取决于质量设置与 libwebp 版本），只能在生成时实测一次并记下来。
     */
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

    /**
     * 编码 尺寸 => 字节数 为 `thumb_bytes` 列值。
     *
     * 与 encodeThumbs 同策略：**始终写入 `ok:1` 标记**，保证列非空。
     *
     * v1.5.0-beta.3：本列**不再**是选行判据（那是 `processing_state` 的职责）。保留标记的
     * 意义变成：与 `thumbs` 一起推导状态时，能区分「处理过但一档都没测到」(failed)
     * 与「从未处理」(pending) —— 前者可重试，后者是「补全」按钮的职责。
     */
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

    /** 解析 `processing_state` JSON 列为 处理项 => 状态（非法值忽略）。 */
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

    /**
     * 某处理项的当前状态。
     *
     * **键缺失 → `pending`（从未处理）** —— 这条默认值是「存量行无需回填」的全部依据：
     * 加列时所有旧行的 `processing_state` 都是 NULL，而它们天然应当被视作"还没补过"。
     */
    public function processingState(string $item = self::ITEM_THUMB_META): string
    {
        return self::decodeProcessingState((string) ($this->attributes['processing_state'] ?? ''))[$item]
            ?? self::STATE_PENDING;
    }

    /** 写入某处理项的状态（**保留**其它处理项的键）。 */
    public static function withProcessingState(?string $json, string $item, string $state): string
    {
        $map = self::decodeProcessingState((string) $json);
        $map[$item] = $state;
        return (string) json_encode($map, JSON_UNESCAPED_SLASHES);
    }

    /**
     * 由现有数据**推导** thumb_meta 的状态 —— 唯一来源。
     *
     * 上传路径与补全工具共用本函数，避免两处各判一套（否则同一个行在两个入口会得到
     * 不同状态，而两边都不会报错）。
     *
     * 推导规则（顺序即优先级）：
     *   ① thumbs 为 NULL 或空串 → `pending`
     *      —— 从未处理过；或上传时总开关关闭（上传路径刻意写空串，好让它留在待补全集合里）
     *   ② thumbs 处理过、但解析不出任何档位 → `skipped`
     *      —— 源图小于所有档位（不放大）/ 不可解码 / 内存预检跳过，属**合法无需处理**
     *   ③ thumbs 里每一档都有实测字节数 → `ok`
     *   ④ 只有部分档位有 → `partial`
     *   ⑤ 一档都没有 → `failed`（该有结果却没拿到，可重试）
     *
     * 注意 ③ 与 ④ 的判据是「**thumbs 里列出的**档位」——上传时某档上传失败的行，
     * 该档根本不在 thumbs 里，因此仍算 `ok`（没有对象 = 无从测量，不是缺陷）。
     */
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

    /**
     * 「待补全」的 SQL 判据 —— **唯一来源**（v1.5.0-beta.3 起由状态列驱动）。
     *
     * 为什么改成状态驱动（此前是 `thumbs/thumB_bytes 为空`）：那套判据无法表达
     * 「已经试过、但对象长度读不到」—— 这样的行要么被反复重选（队列空转，前端因
     * `remaining` 不归零而无限循环），要么被永久放弃。状态列把「未处理」与
     * 「处理失败」分开，于是失败行**离开队列**（不阻塞）却**仍可被定向重试**。
     *
     * 兼容性：`processing_state` 为 NULL（存量行）/ 非法 JSON / 缺键，一律视作 pending，
     * 因此**不需要停机回填**。`JSON_VALID` 防护是为了手工改坏的列值不至于让整条 SQL 报错。
     */
    public static function thumbMetaPendingSql(): string
    {
        return self::thumbMetaExpr() . " = '" . self::STATE_PENDING . "'";
    }

    /**
     * 「可重试」的 SQL 判据 —— 唯一来源。
     *
     * 只选 `partial` / `failed`：前者表示部分档位读不到长度，后者表示一档都没拿到。
     * `skipped` 刻意**不在**其中（本来就没有对象，重试多少次都一样）；
     * `ok` / `pending` 也不在（分别是不需要、以及「补全」按钮的职责）。
     */
    public static function thumbMetaRetryableSql(): string
    {
        return self::thumbMetaExpr() . " IN ('" . self::STATE_PARTIAL . "', '" . self::STATE_FAILED . "')";
    }

    /**
     * `processing_state.thumb_meta` 归一化后的 SQL 值表达式（NULL/非法 JSON → 'pending'）。
     *
     * 为什么要有生成列：JSON_VALID / JSON_EXTRACT 是**非 sargable** 的，而
     * `processing_state` 也不在任何索引里。状态卡片的两个派生计数
     *（no_thumb / meta_retryable）因此必须回表逐行解析 JSON —— 10 万行 95% 是
     * done 时，每个批次轮询就是约 9.5 万次随机回表。
     *
     * `thumb_meta` 是 VIRTUAL 生成列（见 Application::ensureThumbMetaColumn），其
     * 表达式与这里的降级分支**逐字等价**；配合
     * `idx_status_thumb_meta(process_status, thumb_meta)` 后那两条计数变成覆盖索引扫描。
     *
     * ⚠️ 有列时直接返回裸列名，**不能**再套 COALESCE —— 一旦套上函数，
     * 优化器就用不了索引，生成列也就白加了。（列定义里已含 COALESCE，故无需再包。）
     *
     * 降级：老库若加列失败（权限不足 / 老版本 MySQL），SHOW COLUMNS 探测不到就退回
     * JSON 表达式 —— 查询仍正确，只是慢。绝不让加列失败演变成功能不可用。
     */
    private static function thumbMetaExpr(): string
    {
        if (self::hasThumbMetaColumn()) {
            return '`thumb_meta`';
        }
        $path = '$.' . self::ITEM_THUMB_META;
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '{$path}')), '"
            . self::STATE_PENDING . "')";
    }

    /** `images.thumb_meta` 生成列是否可用（请求内缓存一次）。 */
    private static function hasThumbMetaColumn(): bool
    {
        if (self::$thumbMetaColumn !== null) {
            return self::$thumbMetaColumn;
        }
        try {
            $stmt = Database::getInstance()->query("SHOW COLUMNS FROM `images` LIKE 'thumb_meta'");
            return self::$thumbMetaColumn = $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            // 探测失败按「没有」处理：退回 JSON 表达式，结果仍然正确。
            return self::$thumbMetaColumn = false;
        }
    }

    /**
     * 某尺寸缩略图的**实测**字节数。
     *
     * `null` = 未知（尚未生成、或存量行还没跑过补全）—— 调用方**必须**按未知处理，
     * 绝不能当成 0，也不能拿原图的字节数顶替（那是谎报）。
     */
    public function thumbBytesFor(string $size): ?int
    {
        return self::decodeThumbBytes((string) ($this->attributes['thumb_bytes'] ?? ''))[$size] ?? null;
    }

    public function category(): ?Category
    {
        if (!$this->category_id) return null;
        return Category::find($this->category_id);
    }

    /**
     * Public URL of the image —— 由 `path` 经所属存储驱动**实时生成**。
     *
     * 不再回退 `url` 列（v2.0.0-beta.19）：所有写入路径（上传 / 转 WebP）都把它写
     * 成空串，回退分支永远不命中；留着只会让人以为"库里还存着一份 URL"。历史行的
     * 非空 url 由 `src/cli/migrate-legacy-refs.php` 一次性清空。
     *
     * 生成失败时返回空串（不抛）：渲染一页图不该因单行存储配置异常而 500。
     * 调用方拿空串会走 404 / 占位图，同时 error_log 留下可排查的痕迹。
     */
    public function url(): string
    {
        return $this->driverUrlFor((string) ($this->attributes['path'] ?? ''));
    }

    /**
     * v1.5.0-beta.1 性能: 请求内 URL 记忆化。
     *
     * 同一张图同一个键常在一页里被多次请求（`src` 取 sm，`srcset` 又含 sm；
     * displayUrl 先试请求尺寸再回退 md）—— 每调用一次就要重新签名/预签名。
     * 云端预签名是 HMAC + 客户端调用，重复计算纯属浪费，故按 (实例, 键) 记忆。
     * 失败结果（空串）同样记忆：同一请求内不必反复重试同一个坏配置。
     *
     * @var array<string, string>
     */
    private static array $urlMemo = [];

    /** `images.thumb_meta` 生成列的存在性缓存（null = 尚未探测）。 */
    private static ?bool $thumbMetaColumn = null;

    /** 经存储驱动生成某键的 URL（请求内记忆化）。 */
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
            // 不抛（渲染不应被单行配置拖垮），但也不静默：这是"图片地址生成失败"
            // 的唯一留痕点，缺它时线上只表现为若干张图空白、日志里什么都没有。
            error_log('[MoeRNG] 图片 URL 生成失败（key=' . $key . '）: ' . $e->getMessage());
            $url = '';
        }
        return self::$urlMemo[$memoKey] = $url;
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
        return $this->driverUrlFor($this->thumbKeyFor($size));
    }

    /**
     * 展示用图片地址（带回退链，永不返回空——除非该行彻底无路径）：
     *   请求尺寸缩略图 → md 缩略图 → 原图。
     * 视图直接用它，避免每处都写 `?:` 三元。
     */
    public function displayUrl(string $size = self::THUMB_DEFAULT): string
    {
        return $this->displayUrlWithSize($size)['url'];
    }

    /**
     * 同 displayUrl，但**同时回报实际生效的尺寸**。
     *
     * v1.5.0-beta.2（API 缩略图获取）：调用方（尤其 API）必须能知道"我要的 sm
     * 并不存在、实际给的是 md" —— 否则它会以为拿到了 320px 的图。
     * **兜底链只此一处实现**，displayUrl 委托过来，避免两条链各自演化后不一致。
     *
     * @return array{url: string, size: ?string} size = null 表示链走到底、回退到原图。
     */
    public function displayUrlWithSize(string $size = self::THUMB_DEFAULT): array
    {
        // 原图是链的**终点**，必须先拦下。缩略图 URL 对 'original' 恒为空串
        //（它不是 thumbs 里的键），若继续往下走，`$size !== THUMB_DEFAULT` 成立
        // → 回退到 md —— 请求原图反而拿到更小的缩略图。这条分支曾经缺失，而
        // size 缺省就是原图，等于所有不传 size 的调用方都在拿 md。
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
            $where  = "status = 'active' AND process_status = 'done' AND category_id IN ({$placeholders})";
            $params = $ids;
        } else {
            $where  = "status = 'active' AND process_status = 'done'";
            $params = [];
        }

        $pdo = Database::getInstance();
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM images WHERE {$where}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
        if ($total === 0) return null;

        // MR-33 修复: COUNT 与 SELECT 非同一快照（REPEATABLE READ 下两次语句各自
        // 取快照），并发删图窗口内 OFFSET 可能落在存活集之外 → fetch 为空 → 原实现
        // 直接返回 null，公开随机图 API 偶发「无图」。这里取空时重查一次（重新随机
        // 偏移）；仍空则回退同条件 LIMIT 1（不取偏移）——只要库里还有符合条件的图
        // 就必返回一张。返回结构（?self）与前台 API 行为不变。
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $offset = random_int(0, $total - 1);
            $stmt = $pdo->prepare("SELECT * FROM images WHERE {$where} LIMIT 1 OFFSET {$offset}");
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) return self::hydrate($row);
        }

        $stmt = $pdo->prepare("SELECT * FROM images WHERE {$where} LIMIT 1");
        $stmt->execute($params);
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

        // 防御：极端并发（COUNT 后行被删）导致取不满时，从头补齐。
        // MR-34 修复：补齐查询必须排除已拿到的 id——否则 offset 后的尾部与
        // 「从头取」的首段会重叠，同一张图在图库页渲染两次（仿真 dup=[2]）。
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
     * MR-35 修复: 请求级缓存 —— 整棵 `categories` 表的 `parent_id => [childId,...]`
     * 邻接表。原 getCategoryAndChildIds 逐节点一发 SELECT（N 节点 = N 次查询，
     * 公开随机图 API 必经）。改为一次取出全表 id/parent_id 后 PHP 侧 BFS 收集子树，
     * 整请求仅 1 次查询。与本文件既有 `$urlMemo` 同模式：PHP-FPM 每请求独立进程，
     * static 属性天然请求级；CLI 长驻进程共享可接受。
     *
     * @var array<int, array<int, int>>|null
     */
    private static ?array $categoryChildrenMap = null;

    /** 一次取出全部分类的 id/parent_id，组邻接表（请求内缓存）。 */
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
     * 取某分类及其全部后代分类的 id 集合（含自身）。
     *
     * MR-35: 由「逐节点递归 SELECT」改为「整表一次取出 + PHP 侧 BFS」，整请求 1 次查询。
     * 返回语义不变：含自身 + 全部后代、去重、array_values（调用方仅作 IN(...) 集合，
     * 顺序无关）。脏数据自引用环由 `$seen` 集合守卫（等价原 depth>20 硬停）。
     *
     * v2.0.0-beta.19: 删掉从未被读取的 `$depth` 参数 —— 它保留着"可限制深度"的
     * 假象，而实现（BFS）根本不看它，两参调用会**静默返回整棵子树**。
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
        // v2.0.0-beta.17 修复: 此前只删除主文件 `path` —— 同前缀的三档缩略图
        // （thumb-sm/md/lg.webp）残留在对象存储，删除后文件变孤儿。
        // 现在按 `thumbs` JSON（权威登记，thumb_path 列 = md 冗余）逐 key 删除。
        $storage = self::driverFor($this);
        $failed = [];
        foreach ($this->allStorageKeys() as $key) {
            try {
                if (!$storage->delete($key)) {
                    $failed[] = $key;
                }
            } catch (\Throwable) {
                $failed[] = $key;
            }
        }
        $deleted = parent::delete();
        if ($deleted) {
            // MR-38: 删除动作本身留痕（与 image_delete_storage_failed 并存——
            // 后者是「DB 已删但对象存储残留孤儿文件」的排障事件，前者是删除动作本身）。
            // 动作码 image_delete 与 DashboardController actionLabels 的「删除图片」对齐。
            // AuditLog::record 内部全包 try/catch，best-effort，不阻断删除主流程。
            \App\Models\AuditLog::record('image_delete', [
                'id'       => (int) $this->attributes['id'],
                'filename' => (string) ($this->attributes['filename'] ?? ''),
                'path'     => (string) ($this->attributes['path'] ?? ''),
                'storage'  => (string) ($this->attributes['storage'] ?? ''),
            ]);
            if (!empty($failed)) {
                // 存储删除失败但 DB 已删：文件将成为孤儿，记审计便于排查。
                \App\Models\AuditLog::record('image_delete_storage_failed', [
                    'id'   => (int) $this->attributes['id'],
                    'paths' => $failed,
                ]);
            }
        }
        return $deleted;
    }

    /** 主文件 + 全部缩略图的存储 key（去空去重）。 */
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
