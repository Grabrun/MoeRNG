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
        $path = '$.' . self::ITEM_THUMB_META;
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '{$path}')), '"
            . self::STATE_PENDING . "') = '" . self::STATE_PENDING . "'";
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
        $path = '$.' . self::ITEM_THUMB_META;
        $col = "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '{$path}'))";
        return "{$col} IN ('" . self::STATE_PARTIAL . "', '" . self::STATE_FAILED . "')";
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
            $url = $this->driverUrlFor($path);
            if ($url !== '') return $url;
        }
        return (string) ($this->attributes['url'] ?? '');
    }

    /**
     * v1.5.0-beta.1 性能: 请求内 URL 记忆化。
     *
     * 同一张图同一个键常在一页里被多次请求（`src` 取 sm，`srcset` 又含 sm；
     * displayUrl 先试请求尺寸再回退 md）—— 每调用一次就要重新签名/预签名。
     * 云端预签名是 HMAC + 客户端调用，重复计算纯属浪费，故按 (实例, 键) 记忆。
     * 失败结果（空串）同样记忆：调用方会回退到库里的 url 列，不必反复重试。
     *
     * @var array<string, string>
     */
    private static array $urlMemo = [];

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
        } catch (\Throwable) {
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
