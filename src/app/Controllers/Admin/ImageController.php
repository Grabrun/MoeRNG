<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Session;
use App\Models\Image;
use App\Models\Category;
use App\Models\StorageProfile;

class ImageController extends Controller
{
    private const PAGE = '/admin/images';

    /**
     * v1.3.3-beta.1: 重型端点（GD 解码 + 对象存储上传）的内存上限。
     *
     * 注意：**不要靠调大这个值来解决大图问题** —— PHP 致命错误（OOM）不可捕获，
     * 一旦发生整批请求都会死掉。真正的防线是 decodeWouldExceedMemory() 的
     * 解码前像素预检（超限则跳过缩略图，原图不受影响）。
     */
    private const HEAVY_MEMORY_LIMIT = '512M';

    /** GD 解码内存系数：位图为 w×h×4 字节，libwebp/重采样还需额外缓冲。 */
    private const DECODE_MEMORY_FACTOR = 1.25;

    /** 解码额外余量（目标画布 sm/md/lg + 编码缓冲 + 基线开销）。 */
    private const DECODE_MEMORY_HEADROOM = 33554432; // 32 MiB

    /**
     * 本请求正在处理的行 id —— jsonFatalGuard 的 shutdown 钩子用它把"因致命错误
     * 而中断"的行标记为 failed，避免它们留在 processing 被下轮复位后无限重试
     * （线上曾表现为整个队列永久卡住、进度数字不动）。
     */
    private static array $inflightIds = [];

    private array $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/bmp',
        // SVG deliberately excluded (CVE-2026-MR-003): an <img>-served SVG can
        // carry <script>/<iframe> payloads (stored XSS on the admin grid);
        // CSP cannot fully neutralize scripts inside document-loaded SVGs.
        // Logo upload already excludes SVG for the same reason.
    ];

    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function index(Request $request): void
    {
        $page = max(1, (int) $request->input('page', '1'));
        // v1.2.0 迭代: per-page selector (?per_page=10/20/50/100), falls back
        // to the global settings value when absent or not in the whitelist.
        $perPage = (int) $request->input('per_page', '0');
        $perPage = in_array($perPage, [10, 20, 50, 100], true)
            ? $perPage
            : (int) Config::get('settings.per_page', '20');
        $search = $request->input('search', '');
        $categoryId = $request->input('category_id', '');

        $where = '1=1';
        $params = [];

        if ($search) {
            $where .= " AND (original_name LIKE ? OR filename LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($categoryId !== '') {
            if ($categoryId === '0' || $categoryId === 'null') {
                $where .= " AND category_id IS NULL";
            } else {
                $where .= " AND category_id = ?";
                $params[] = (int) $categoryId;
            }
        }

        $result = Image::paginate($page, $perPage, 'sort_order ASC, id DESC', $where, $params);
        $categories = Category::all('sort_order ASC');
        $storageProfiles = StorageProfile::enabledAll();
        $defaultProfile = StorageProfile::defaultProfile();

        $this->render('admin/images', [
            'title' => '图片管理',
            'images' => $result['data'],
            'total' => $result['total'],
            'page' => $result['page'],
            'lastPage' => $result['last_page'],
            'perPage' => $perPage,
            'categories' => $categories,
            'search' => $search,
            'categoryId' => $categoryId,
            'storageProfiles' => $storageProfiles,
            'defaultProfile' => $defaultProfile,
        ]);
    }

    /**
     * v1.2.0 迭代: all image ids matching the current filters — powers the
     * cross-page "全选全部" action. Same filter logic as index().
     */
    public function ids(Request $request): void
    {
        $search = $request->input('search', '');
        $categoryId = $request->input('category_id', '');

        $where = '1=1';
        $params = [];
        if ($search) {
            $where .= " AND (original_name LIKE ? OR filename LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($categoryId !== '') {
            if ($categoryId === '0' || $categoryId === 'null') {
                $where .= " AND category_id IS NULL";
            } else {
                $where .= " AND category_id = ?";
                $params[] = (int) $categoryId;
            }
        }

        // Lightweight: SELECT id only (avoids hydrating full Models — the old
        // paginate call returned Model objects, so $r['id'] was array-access
        // on an object -> HTTP 500).
        $sql = 'SELECT id FROM images';
        if ($where !== '1=1') {
            $sql .= " WHERE {$where}";
        }
        $sql .= ' ORDER BY id DESC';
        $rows = Image::query($sql, $params);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $this->json(['ids' => $ids, 'total' => count($ids)]);
    }

    /**
     * v1.3.2-beta.2: 生成缩略图到系统临时文件（GD；不可用时返回 null 表示降级跳过）。
     * 供新上传队列 Worker 与「补全历史缩略图」共用。
     *
     * @return array{0: ?string, 1: ?string} [临时文件路径, 存储 key]，失败/降级为 [null, null]
     */
    /**
     * v1.3.2-beta.2 迭代: 生成**多尺寸**缩略图到系统临时文件（GD + WebP）。
     *
     * 源图只解码一次，按 Image::THUMB_SIZES 逐档缩放：
     *   - sm 320 / md 640 / lg 1280（最大边）
     *   - **不放大**：源图最大边小于某档时跳过该档（避免生成比原图还大的"缩略图"）
     *   - 某档写盘失败只跳过该档，其余档继续（各档独立）
     *
     * 供新上传队列 Worker 与「补全历史缩略图」共用（单一实现，无重复 GD 逻辑）。
     *
     * @return array{readable: bool, thumbs: array<string, array{tmp: string, key: string}>, reason: string}
     *   readable=false → 源图无法解码 / 过大（内存预检拒绝）/ GD 不可用，调用方按降级处理；
     *   reason: ok | too-large | undecodable | gd-unavailable
     *   readable=true 且 thumbs 为空 → 源图本身小于所有档位（合法空操作）。
     */
    // ── v1.4.0-beta.2: 系统设置「图片与存储」分组读取（单一来源）────────────
    // 设置可能从未保存过（Config 里没有该键）→ 一律带默认值兜底。

    /** 是否生成缩略图（总开关，默认开）。 */
    private static function thumbsEnabled(): bool
    {
        return (string) Config::get('settings.thumbs_enabled', '1') !== '0';
    }

    /** 缩略图 WebP 编码质量（40-100，默认 82）。 */
    private static function thumbQuality(): int
    {
        $q = (int) Config::get('settings.thumb_quality', '82');
        return max(40, min(100, $q > 0 ? $q : 82));
    }

    /** 缩略图像素上限（像素数；0 = 不设上限，仅按可用内存判断）。 */
    private static function thumbMaxPixels(): int
    {
        $wan = (int) Config::get('settings.thumb_max_pixels', '0');   // 单位：万像素
        return $wan > 0 ? $wan * 10000 : 0;
    }

    /** 单图大小上限（字节；0 = 不限制，只受 PHP 配置约束）。 */
    private static function uploadMaxBytes(): int
    {
        $mb = (int) Config::get('settings.upload_max_mb', '0');
        return $mb > 0 ? $mb * 1048576 : 0;
    }

    /** v1.5.0-beta.1: 原图是否统一转 WebP（默认开）。 */
    private static function originalWebpEnabled(): bool
    {
        return (string) Config::get('settings.original_webp_enabled', '1') !== '0';
    }

    /**
     * 编码输出的临时后缀（与目的地同目录 → rename 是原子替换）。
     *
     * v1.5.0-beta.2：原图转码改为「先写临时文件、判定值得替换后才 rename 覆盖」，
     * 源文件在明确成功之前一个字节都不动。
     */
    private const WEBP_TEMP_SUFFIX = '.wip-webp';

    /** 原图转码额外余量（编码器内部缓冲 + 收尾；比缩略图那套宽松，因为原图更大）。 */
    private const CONVERT_MEMORY_HEADROOM = 16777216; // 16 MiB

    /**
     * 原图转码预算的安全边际：估算值必须 ≤ 可用内存 × 该系数才放行。
     *
     * 留 15% 余量是为了吸收估算误差 —— 估算公式里的"编码器同级缓冲"是从一次
     * 线上 OOM（imagewebp 在 280 MiB 画布上又申请 280 MiB）反推出来的经验值，
     * 不同 libwebp 版本/编码模式会有出入。宁可多跳过几张并如实报告，
     * 也不要用一次不可捕获的致命错误把整批请求打断。
     */
    private const MEMORY_SAFETY_FACTOR = 0.85;

    /** 原图 WebP 编码质量（40-100，默认 90 —— 原图是质量锚点，故高于缩略图）。 */
    private static function originalWebpQuality(): int
    {
        $q = (int) Config::get('settings.original_webp_quality', '90');
        return max(40, min(100, $q > 0 ? $q : 90));
    }

    /**
     * 把本地图片文件转成 WebP，**写入 `$destFile`**（缺省 = 与源同路径 → 原地替换）。
     *
     * 上传路径与「转换历史原图」共用这一个实现 —— 规则只有一份，不会两边走偏。
     *
     * v1.5.0-beta.2 修复（数据安全）：编码**先落到同目录的临时文件**，只有判定
     * 「确实更小、值得替换」之后才 `rename` 覆盖目的地。此前是
     * `file_put_contents($file, $out)` **直接写源文件** —— 只要调用方传进来的是真实
     * 存储文件（本地驱动就是这种情况），两个场景都会弄坏数据：
     *   - **干跑**：原图被换成 WebP 字节，而记录不改（path 仍是 .jpg、mime 仍是
     *     image/jpeg）→ 记录与实际字节不一致；反复干跑还会反复重编码、逐次劣化画质；
     *   - **中途失败**：回滚只回滚记录，而旧文件已经被覆盖成 WebP 了。
     * 现在源文件在**明确成功之前一个字节都不动**；批量转换的调用方也一律传私有副本。
     *
     * 内存上不再经 `ob_start()` / `ob_get_clean()` 把整幅编码结果抓进 PHP 变量
     * （原图的编码结果可达数 MiB，且与画布同时在内存）——直接把输出路径交给 imagewebp。
     *
     * @return array{ok: bool, note: string, bytes?: int} ok=false 时调用方保持原格式不动。
     *
     * 保持原格式的情形（每条都有明确理由，绝不为了"统一"而牺牲正确性）：
     *   - disabled           开关关闭
     *   - already-webp       已经是 WebP
     *   - format-kept        GIF（GD 只取第一帧，转了就**丢动画**）、SVG（矢量图位图化会失真）
     *   - gd-unavailable     GD 缺 webp 支持
     *   - too-large          解码会超出可用内存（复用缩略图的同一内存预检）
     *   - exif-unavailable   JPEG 且读不到 EXIF：浏览器会按 EXIF 旋转 JPEG，WebP 不会 ——
     *                        不纠正就会把手机照片转"歪"，所以宁可不转
     *   - unreadable/decode-failed/encode-failed/write-failed  读取、编解码或落盘失败
     *   - not-smaller        编码结果**不比原文件小**（小图/已优化图常见）→ 保留原格式
     */
    private function convertOriginalToWebp(string $file, string $mime, ?string $destFile = null): array
    {
        if (!self::originalWebpEnabled()) {
            return ['ok' => false, 'note' => 'disabled'];
        }
        if ($mime === 'image/webp') {
            return ['ok' => false, 'note' => 'already-webp'];
        }
        if (in_array($mime, ['image/gif', 'image/svg+xml'], true)) {
            return ['ok' => false, 'note' => 'format-kept'];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return ['ok' => false, 'note' => 'gd-unavailable'];
        }

        // 与缩略图同一套内存预检：放不下就不解码（避免不可捕获的 OOM）
        $tooBig = $this->decodeWouldExceedMemory($file);
        if ($tooBig !== null) {
            return ['ok' => false, 'note' => 'too-large'];
        }

        // 0 = JPEG 读不到 EXIF（宁可不转，见上方说明）；1 = 正常；2~8 = 需纠正
        $orientation = self::jpegOrientation($file, $mime);
        if ($orientation === 0) {
            return ['ok' => false, 'note' => 'exif-unavailable'];
        }

        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            return ['ok' => false, 'note' => 'unreadable'];
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return ['ok' => false, 'note' => 'decode-failed'];
        }
        unset($data);

        if ($orientation > 1) {
            $src = $this->applyExifOrientation($src, $orientation);
        }

        // 保留透明通道（PNG/WebP 常见），并统一为真彩色
        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($src);
        }
        @imagealphablending($src, false);
        @imagesavealpha($src, true);

        $dest = $destFile ?? $file;
        $tmpOut = $dest . self::WEBP_TEMP_SUFFIX;

        // 直接落盘：不再经 ob_start 把整幅编码结果抓进变量（原图可达数 MiB）
        $encoded = @imagewebp($src, $tmpOut, self::originalWebpQuality());
        imagedestroy($src);

        clearstatcache(true, $tmpOut);
        $outSize = (int) @filesize($tmpOut);
        if ($encoded === false || $outSize <= 0) {
            @unlink($tmpOut);
            return ['ok' => false, 'note' => 'encode-failed'];
        }

        $before = (int) @filesize($file);
        if ($before > 0 && $outSize >= $before) {
            // 转完更大 → 保留原格式（"优化"不能反而让站点更慢）；**源文件一个字节都没动**
            @unlink($tmpOut);
            return ['ok' => false, 'note' => 'not-smaller'];
        }

        // 到这一步才允许动目的地（同目录 rename = 原子替换；失败则源文件依旧完好）
        if (!@rename($tmpOut, $dest)) {
            @unlink($tmpOut);
            return ['ok' => false, 'note' => 'write-failed'];
        }

        return ['ok' => true, 'note' => 'done', 'bytes' => $outSize];
    }

    /**
     * 读 JPEG 的 EXIF Orientation（v1.5.0-beta.2：从 convertOriginalToWebp 提取，
     * 内存预算预检也要用同一份判断 —— 旋转与否直接决定峰值是否翻倍）。
     *
     * @return int 0 = JPEG 但 exif 扩展不可用（调用方据此放弃转换）；
     *             1 = 无需纠正（含非 JPEG）；2~8 = EXIF 方向值。
     */
    private static function jpegOrientation(string $file, string $mime): int
    {
        if ($mime !== 'image/jpeg') {
            return 1;
        }
        if (!function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data($file);
        if (is_array($exif) && isset($exif['Orientation'])) {
            $o = (int) $exif['Orientation'];
            return ($o >= 2 && $o <= 8) ? $o : 1;
        }
        return 1;
    }

    /**
     * v1.5.0-beta.2: **原图转码专用的内存预算**（不能沿用缩略图那套）。
     *
     * 两次线上故障把口径校准了出来：
     *   ① 干跑返回**空响应 500** —— 端点没有致命守卫（已修）；
     *   ② 执行转换时守卫报出关键数据：
     *      `Allowed memory size of 536870912 bytes exhausted (tried to allocate
     *       293601280 bytes) @ ImageController.php:297`，
     *      297 行正是 **imagewebp（编码）**：解码后的画布本身就是 280 MiB
     *      （w×h×4 ⇒ 约 73 MP），编码器又申请了一块 280 MiB → 两块共 560 MiB
     *      > 512 MiB 上限 → 必然 OOM。
     *
     * **教训：编码器不是"几乎不占内存"。** 缩略图路径能用小系数糊过去，是因为它
     * 的编码目标是几十像素的小图；原图转码的编码目标与原图同尺寸，libwebp 在
     * 编码期至少要再一块与画布同级的缓冲（实测恰好 ≈ w×h×4）。上一版预算只留了
     * 25% 余量（≈73 MiB），于是 73 MP 那张被"放行"到 imagewebp 才炸。
     *
     * 背景（线上真实故障）：干跑「转换历史原图为 WebP」返回**空响应 HTTP 500** ——
     * 那是 PHP 致命错误（不可捕获），因为这个端点既没有致命错误守卫，也没有按原图
     * 的真实峰值做预算。缩略图路径的预检按 `w×h×4×1.25 + 32MiB` 估算，而原图转码的
     * 峰值还要多出：
     *   - `file_get_contents()` 读进来的**整个原始字节**（与画布同时在内存）；
     *   - **EXIF 旋转时 `imagerotate` 必然新建的第二块画布**（≈ 再 +w×h×4）——
     *     手机竖拍照片（orientation 6/8）全在此列，于是实际峰值接近预算的 2 倍；
     *   - 编码结果与 libwebp 内部缓冲。
     *
     * 另外这里**刻意不套用 `thumb_max_pixels`**：那是"生成缩略图的成本上限"，
     * 拿它限制原图会把合法的超大原图误判成"超出设置上限"（原图的正确性上限是内存）。
     *
     * @return array{code: string, detail: string}|null null = 预算内，可以转码。
     */
    private function originalConvertBudget(string $file, string $mime): ?array
    {
        $info = function_exists('getimagesize') ? @getimagesize($file) : false;
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return ['code' => 'dimensions-unavailable', 'detail' => '无法读取图像尺寸（文件损坏或格式不受支持）'];
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w <= 0 || $h <= 0) {
            return ['code' => 'dimensions-unavailable', 'detail' => '图像尺寸无效（' . $w . '×' . $h . '）'];
        }

        $canvas = (float) $w * $h * 4;                 // 一块真彩画布（GD 真彩 = 4 B/px）
        $bytes  = (int) @filesize($file);              // file_get_contents 会把**整个文件**读进内存
        $rotate = self::jpegOrientation($file, $mime) >= 2;

        // 按**分阶段的峰值**取最大值（三个阶段不会同时达到峰值）：
        $decode = $bytes + $canvas;                    // ① 解码：整个文件字节 + 画布
        $encode = $canvas * 2;                         // ② 编码：画布 + 编码器同级缓冲（实测 ≈ 一块画布）
        $rot    = $rotate ? $canvas * 2 : 0;           // ③ EXIF 旋转：imagerotate 期间新旧两块画布并存
        $need   = (int) (max($decode, $encode, $rot) + $canvas * 0.25)
                + self::CONVERT_MEMORY_HEADROOM;

        $limit = self::memoryLimitBytes();
        if ($limit === PHP_INT_MAX) {
            return null;   // 无内存限制 → 交给解码器
        }
        $available = $limit - memory_get_usage(true) - 16777216;   // 再留 16 MiB 基线余量
        if ($available <= 0 || $need > (int) ($available * self::MEMORY_SAFETY_FACTOR)) {
            return [
                'code' => 'memory-budget',
                'detail' => sprintf(
                    '超出内存预算（%d×%d，预计峰值 %.0f MiB%s；可用 %.0f MiB，memory_limit=%s）',
                    $w,
                    $h,
                    $need / 1048576,
                    $rotate ? '，含 EXIF 旋转的两块画布' : '',
                    max(0, $available) / 1048576,
                    (string) ini_get('memory_limit')
                ),
            ];
        }
        return null;
    }

    /** 批量转换用的私有临时路径（storage/incoming，web 不可达；调用方负责清理）。 */
    private function tempConvertPath(int $id): string
    {
        $dir = self::incomingDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/convert-' . $id . '-' . bin2hex(random_bytes(6));
    }

    /**
     * 按 EXIF Orientation 纠正方向。
     *
     * 浏览器对 JPEG 会应用 EXIF 方向，对 WebP **不会** —— 所以转码前必须自己摆正，
     * 否则手机竖拍的照片会转 90°。映射遵循 EXIF 规范（2~8，1 为正常）。
     */
    private function applyExifOrientation(\GdImage $img, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2:
                @imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = imagerotate($img, 180, 0);
                break;
            case 4:
                @imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $img = imagerotate($img, -90, 0);
                @imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $img = imagerotate($img, -90, 0);
                break;
            case 7:
                $img = imagerotate($img, 90, 0);
                @imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $img = imagerotate($img, 90, 0);
                break;
        }
        return $img;
    }

    /**
     * v1.4.0-beta.2 性能修复: 请求内存储实例解析缓存。
     *
     * 处理队列 / 补全缩略图 / 哈希回填三个循环此前对**每一行**都查一次
     * `StorageProfile::find()`（行里没记实例时还要再查一次默认实例）——这就是
     * 典型的 N+1：同一批次里实例通常完全相同，却要被反复查询。
     * 按 id 记忆化后，一次请求最多 1 次 find + 1 次 defaultProfile 查询。
     *
     * 实例属性即请求级缓存（控制器按请求实例化），无需外部传引用；
     * 这些端点不会在本请求内修改存储实例，故不会读到脏数据。
     */
    private array $profileMemo = [];

    private function resolveProfile(?int $profileId): ?StorageProfile
    {
        $key = $profileId ?? 0;
        if (!array_key_exists($key, $this->profileMemo)) {
            $p = $profileId !== null ? StorageProfile::find($profileId) : null;
            if ($p === null) {
                $p = StorageProfile::defaultProfile();
            }
            $this->profileMemo[$key] = $p;
        }
        return $this->profileMemo[$key];
    }

    /** 解析 memory_limit（含 -1 = 无限制）为字节数。 */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * v1.3.3-beta.1 修复（生产 P0）: 解码前的**像素规模预检**。
     *
     * 背景：GD 解码需在内存中展开整幅位图（约 w×h×4 字节，libwebp 还需额外缓冲）。
     * 一张超大 WebP/JPEG 会直接打爆 memory_limit → PHP 致命错误（**不可捕获**）→
     * 整批请求死掉、DB 不更新、该行留在 processing 被下轮复位重试 →
     * 队列永久卡住、进度数字不动（线上现象根因）。
     *
     * getimagesize() 只读文件头、成本极低，因此先算清"解码这张图要多少内存"，
     * 放不下就**跳过缩略图生成**（readable=false 降级）——原图此时已上传入库，
     * 不受影响，只是该行没有缩略图（会计入 no_thumb 统计并写 error_log）。
     *
     * @return ?string null = 可以解码；字符串 = 不能解码的原因
     */
    private function decodeWouldExceedMemory(string $file): ?string
    {
        $info = function_exists('getimagesize') ? @getimagesize($file) : false;
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return '无法读取图像尺寸（文件损坏或格式不受支持）';
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w <= 0 || $h <= 0) {
            return '图像尺寸无效（' . $w . '×' . $h . '）';
        }

        // v1.4.0-beta.2: 系统设置里可显式设一个像素上限（0 = 不设，仅按内存判断）
        $pixels = $w * $h;
        $cap = self::thumbMaxPixels();
        if ($cap > 0 && $pixels > $cap) {
            return sprintf('图像超出设置上限（%d×%d = %.1f 万像素，上限 %.0f 万像素）', $w, $h, $pixels / 10000, $cap / 10000);
        }

        $need = (int) ((float) $w * $h * 4 * self::DECODE_MEMORY_FACTOR) + self::DECODE_MEMORY_HEADROOM;
        $limit = self::memoryLimitBytes();
        if ($limit === PHP_INT_MAX) {
            return null;   // 无内存限制 → 交给解码器
        }
        $available = $limit - memory_get_usage(true) - 16777216;   // 再留 16 MiB 基线余量
        if ($need > $available) {
            return sprintf(
                '图像过大（%d×%d，解码约需 %.0f MiB，当前可用 %.0f MiB）',
                $w,
                $h,
                $need / 1048576,
                max(0, $available) / 1048576
            );
        }
        return null;
    }

    private function makeThumbnails(string $srcFile, string $mime, string $path): array
    {
        // v1.4.0-beta.2: 系统设置「图片与存储」的总开关 —— 关闭时完全不生成缩略图
        // （只存原图）。调用方据此把 thumbs 留空，重新开启后仍可被「补全缩略图」收录。
        if (!self::thumbsEnabled()) {
            return ['readable' => false, 'thumbs' => [], 'reason' => 'disabled'];
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return ['readable' => false, 'thumbs' => [], 'reason' => 'gd-unavailable'];
        }

        // —— 解码前预检：内存放不下（或超过设置上限）就不解码（避免不可捕获的 OOM）——
        $tooBig = $this->decodeWouldExceedMemory($srcFile);
        if ($tooBig !== null) {
            error_log('[MoeRNG] 跳过缩略图生成：' . $tooBig . ' — 路径 ' . $path . '（原图已入库，仅无缩略图）');
            return ['readable' => false, 'thumbs' => [], 'reason' => 'too-large'];
        }

        $src = $this->decodeImage($srcFile, $mime);
        if ($src === null) {
            return ['readable' => false, 'thumbs' => [], 'reason' => 'undecodable'];
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $quality = self::thumbQuality();   // v1.4.0-beta.2: 来自系统设置（默认 82）
        $out = [];

        foreach (\App\Models\Image::THUMB_SIZES as $size => $target) {
            // 尺寸推导与读取端（API 要如实回报"返回的那张图"的宽高）**共用同一份实现**，
            // 所以接口报出来的宽高必然等于实际生成的那张图的宽高，不会两处各算一套。
            $dims = \App\Models\Image::thumbDimensions($w, $h, $target);
            if ($dims === null) {
                continue;   // 不放大（原图长边本就不超过该档位）
            }
            [$tw, $th] = $dims;

            $dst = imagecreatetruecolor($tw, $th);
            // 保留透明通道（PNG/WebP 带 alpha）
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            $tmp = tempnam(sys_get_temp_dir(), 'moerng-thumb-' . $size . '-');
            $ok = $tmp !== false && imagewebp($dst, $tmp, $quality);
            imagedestroy($dst);
            if (!$ok) {
                if ($tmp !== false) @unlink($tmp);
                continue; // 该档失败，其余档继续
            }
            // v1.5.0-beta.2: 字节数**生成时实测**（webp 编码结果取决于质量设置与
            // libwebp 版本，事后无法推导）→ 随 thumbs 一起入库，供 API 如实回报 file_size。
            $bytes = (int) @filesize($tmp);
            $out[$size] = [
                'tmp' => $tmp,
                'key' => \App\Models\Image::thumbKey($size, $path),
                'bytes' => $bytes > 0 ? $bytes : null,   // 0 = 未知，绝不谎报
            ];
        }

        imagedestroy($src);
        return ['readable' => true, 'thumbs' => $out, 'reason' => 'ok'];
    }

    /** 解码图片为 GD 资源（mime 优先，失败按 getimagesize 兜底嗅探）。 */
    private function decodeImage(string $file, string $mime)
    {
        $open = function (string $m) use ($file) {
            if ($m === 'image/jpeg') return @imagecreatefromjpeg($file);
            if ($m === 'image/png') return @imagecreatefrompng($file);
            if ($m === 'image/gif') return @imagecreatefromgif($file);
            if ($m === 'image/webp' && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($file);
            return null;
        };

        $src = $open($mime);
        if ($src === null || $src === false) {
            // mime 缺失/不准时按文件头嗅探
            $info = function_exists('getimagesize') ? @getimagesize($file) : false;
            if (is_array($info) && !empty($info['mime'])) {
                $src = $open((string) $info['mime']);
            }
        }
        return ($src === null || $src === false) ? null : $src;
    }

    /**
     * 丢弃「已生成但未及上传」的临时缩略图文件（异常路径防泄漏）。
     * uploadThumbs() 内已用 finally 清理，这里只兜底：任何在其之前抛出的异常
     * （如原图上传失败、DB 写入失败）都不会留下临时文件。
     */
    private function discardThumbs(?array $gen): void
    {
        if (!is_array($gen) || empty($gen['thumbs'])) return;
        foreach ($gen['thumbs'] as $t) {
            if (!empty($t['tmp'])) @unlink($t['tmp']);
        }
    }

    /**
     * 把生成的各档缩略图上传到目标存储，返回 尺寸 => key（失败档自动跳过）。
     * 无论成功与否都会删除临时文件。
     */
    private function uploadThumbs(\App\Storage\StorageInterface $driver, array $thumbs): array
    {
        $keys = [];
        $bytes = [];
        foreach ($thumbs as $size => $t) {
            try {
                $driver->upload($t['tmp'], $t['key'], 'image/webp');
                $keys[$size] = $t['key'];
                // 只记**上传成功**档的字节数（失败档的对象不存在，记了就是谎报）
                $b = (int) ($t['bytes'] ?? 0);
                if ($b > 0) {
                    $bytes[$size] = $b;
                }
            } catch (\Throwable) {
                // 单档上传失败不影响其它档（该档缺失时前端回退到 md/原图）
            } finally {
                @unlink($t['tmp']);
            }
        }
        return ['keys' => $keys, 'bytes' => $bytes];
    }

    /**
     * v1.3.3-beta.1: 给重型端点（GD 解码 / 对象存储上传）装上「致命错误也返回 JSON」的保险。
     *
     * 背景：PHP 致命错误（内存耗尽 / 执行超时 / 未捕获 Error）会让响应体**为空**，
     * 前端只能报 "Unexpected end of JSON input"，完全看不到真实原因。
     *
     * 同时提升资源上限（见 HEAVY_MEMORY_LIMIT 常量）：单张 4000×6000 的 JPEG 解码
     * 就需约 96MB（w×h×4 字节），默认 memory_limit=128M 下批量处理必然 OOM。
     * 但**调大上限不是解法** —— 真正的防线是 decodeWouldExceedMemory() 的预检；
     * 本保险的作用是：万一仍发生致命错误，①返回可读 JSON，②把中断的行标记为
     * failed 而不是让队列永久卡住。
     */
    private function jsonFatalGuard(string $label): void
    {
        @ini_set('memory_limit', self::HEAVY_MEMORY_LIMIT);
        @set_time_limit(120);

        register_shutdown_function(static function () use ($label): void {
            $e = error_get_last();
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!$e || !in_array($e['type'], $fatalTypes, true)) {
                return; // 正常结束（或仅有 warning/notice）→ 不干预
            }
            $detail = $e['message'] . ' @ ' . basename((string) $e['file']) . ':' . $e['line'];

            // v1.3.3-beta.1: 把"因致命错误而中断"的行标记为 failed。
            // 否则它们会留在 processing → 下轮开头被复位为 pending → 再次触发同一个
            // 致命错误 → **队列永久卡住、进度数字不动**（线上现象）。标记为 failed 后
            // 队列可继续推进（不再卡死），这些行由操作员在「重新处理失败项」显式重试。
            // 只更新仍为 processing 的行 —— 已完成的行不受影响。
            $marked = 0;
            if (self::$inflightIds !== []) {
                try {
                    $pdo = \App\Core\Database::getInstance();
                    $ids = array_map('intval', self::$inflightIds);
                    $pdo->prepare(
                        "UPDATE `images` SET `process_status` = 'failed', `process_error` = ?"
                        . " WHERE `process_status` = 'processing' AND `id` IN ("
                        . implode(',', array_fill(0, count($ids), '?')) . ")"
                    )->execute(array_merge(
                        [mb_substr('处理中断（' . $label . ' 致命错误）: ' . $detail, 0, 480)],
                        $ids
                    ));
                    $marked = count($ids);
                } catch (\Throwable) {
                    // 数据库不可用等情况：忽略（下次运行开头仍会复位 processing 行）
                }
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            // v1.5.0-beta.2: 把"是哪种致命错误、撞到哪条上限、卡在哪一行"一并报出来。
            // 只回一句"服务器返回空响应"时，运维既不知道是 OOM 还是超时，也无从下手。
            echo json_encode([
                'success' => false,
                'fatal'   => true,
                'error'   => 'PHP 致命错误（' . $label . '）: ' . $detail,
                'marked_failed' => $marked,
                'memory_limit'  => (string) ini_get('memory_limit'),
                'peak_mb'       => round(memory_get_peak_usage(true) / 1048576, 1),
                'inflight_ids'  => array_slice(array_map('intval', self::$inflightIds), 0, 20),
            ], JSON_INVALID_UTF8_SUBSTITUTE);
        });
    }

    /** v1.3.2-beta.2: 临时存放目录（站点根 storage/incoming，web 不可达）。 */
    public static function incomingDir(): string
    {
        return dirname(__DIR__, 3) . '/storage/incoming';
    }

    /**
     * v1.3.2-beta.2 迭代: 异步处理队列 Worker（管理员驱动，Web 分批）。
     *
     * 每次调用处理一小批 pending 图片：
     *   1. 从 storage/incoming 取临时原图；
     *   2. GD 一次解码生成**多尺寸**缩略图（sm 320 / md 640 / lg 1280 最大边，
     *      webp q82，不放大；无 GD/webp 或源图不可解码时降级为无缩略图，仅搬运原图）；
     *   3. 原图 + 缩略图上传到该图所属的最终存储实例；
     *   4. 记录置 done（path/url/thumb_path 落库）；
     *   5. 删除临时文件（原图与缩略图临时副本）。
     * 失败置 failed + process_error，临时文件保留供重试（requeueFailed 可把
     * failed 重置回 pending）。前端循环调用直到 remaining=0。
     */
    public function processQueue(Request $request): void
    {
        $this->validateCsrf();
        $this->jsonFatalGuard('process-queue');   // 致命错误 → 合法 JSON（而非空响应）

        $batchSize = max(1, min(10, (int) $request->input('batch', '3')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        // v1.3.3-beta.1: 先把上次中断（超时/致命错误）遗留的 'processing' 行复位为
        // pending —— 放在开头，使它们在本轮立即被拾取，而不是白等一轮。
        try {
            $pdo->exec("UPDATE `images` SET `process_status` = 'pending' WHERE `process_status` = 'processing'");
        } catch (\Throwable) {
            // 列缺失等异常忽略：健康检查会提示补列
        }

        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $pendingBefore = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'pending'"
        )->fetchColumn();

        $done = 0;
        $failed = 0;
        $skipped = 0;   // v1.3.3-beta.1: 原图过大而跳过缩略图生成的数量（原图仍正常入库）
        $results = [];

        if ($pendingBefore > 0) {
            $rows = $pdo->query(
                "SELECT * FROM `images` WHERE process_status = 'pending' ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $incomingDir = self::incomingDir();

            // v1.3.3-beta.1: 本批先标记为 'processing' —— ①「处理中」计数从此有真实
            // 含义（此前恒为 0：只有 done/failed 会被写入）；②若本请求中途中断
            // （超时/致命错误），这些行会在下次运行开头被复位并重试。
            // 注意：processing 行对前台不可见（前台只出 process_status='done'）。
            if ($rows !== []) {
                $ids = array_map(static fn($r) => (int) $r['id'], $rows);
                $pdo->exec("UPDATE `images` SET `process_status` = 'processing' WHERE `id` IN (" . implode(',', $ids) . ")");
                // 交给 jsonFatalGuard 的 shutdown 钩子：万一本批中途致命错误，
                // 它会把这几行标记为 failed（否则队列会永久卡在同一批上）。
                self::$inflightIds = $ids;
            }

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                $incomingFile = $incomingDir . '/' . ltrim($path, '/');

                // v1.3.2-beta.2: 声明在 try 外 —— catch 里用它清理未上传的临时缩略图
                $gen = null;

                try {
                    if (!is_file($incomingFile)) {
                        throw new \RuntimeException('临时文件不存在（可能已被清理）');
                    }

                    // —— 该图所属存储实例 ——
                    // v1.4.0-beta.2 性能: 走请求内缓存（原先每行查一次 → N+1）
                    $profile = $this->resolveProfile(
                        $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                    );
                    if ($profile === null) {
                        throw new \RuntimeException('无可用存储实例');
                    }
                    $storage = $profile->driver();

                    // v1.3.3-beta.1 修复: mime 在此统一取用 —— 原先它定义在被替换掉的
                    // 缩略图块里，重排后成为未定义变量（PHP 求值为 null，触发
                    // S3Driver::upload() 的第 3 参数 TypeError）。
                    $mime = (string) ($row['mime_type'] ?? '');

                    // —— 先上传原图到最终存储（失败即抛，此时尚未生成任何临时文件）——
                    $url = $storage->upload($incomingFile, $path, $mime);

                    // —— 多尺寸缩略图（一次解码生成 sm/md/lg；GD 不可用或源图
                    //    不可解码时降级为无缩略图，不阻断原图入库）——
                    $gen = $this->makeThumbnails($incomingFile, $mime, $path);
                    $genReason = (string) ($gen['reason'] ?? '');
                    if ($genReason === 'too-large') {
                        // v1.3.3-beta.1: 原图过大 → 内存预检拦下，跳过缩略图（原图已入库）。
                        // 计入 skipped，前端据此提示操作员；该行仍按 done 收尾（thumbs='{"ok":1}'），
                        // 因此不会反复重试、也不会卡住队列。
                        $skipped++;
                    }

                    // —— 逐档上传缩略图（单档失败不影响其它档）——
                    $thumbUp = $this->uploadThumbs($storage, $gen['thumbs']);
                    $thumbKeys = $thumbUp['keys'];
                    $thumbPath = $thumbKeys['md'] ?? null;

                    // —— 记录置 done（thumbs 恒非空 → 回填队列不会重复选中该行）——
                    // v1.4.0-beta.2: 例外 —— 当「生成缩略图」总开关关闭时**故意留空**，
                    // 使这些行仍属于"缺少缩略图"集合，重新开启后可用「补全历史缩略图」
                    // 一键补齐（否则会被 {"ok":1} 标记永久排除在补全之外）。
                    $thumbsValue = $genReason === 'disabled' ? '' : \App\Models\Image::encodeThumbs($thumbKeys);
                    // v1.5.0-beta.2: 字节数同批入库。总开关关闭时同样留空 ——
                    // 这些行仍留在「待补全」集合里，重新开启后可被补全工具收走。
                    $thumbBytesValue = $genReason === 'disabled' ? '' : \App\Models\Image::encodeThumbBytes($thumbUp['bytes']);
                    $upd = $pdo->prepare(
                        "UPDATE `images` SET `url` = ?, `thumb_path` = ?, `thumbs` = ?, `thumb_bytes` = ?, `process_status` = 'done', `process_error` = NULL WHERE `id` = ?"
                    );
                    $upd->execute([$url, $thumbPath, $thumbsValue, $thumbBytesValue, $id]);

                    // —— 成功后删除临时原图 ——
                    @unlink($incomingFile);
                    // 空的日期目录顺手清理（忽略失败）
                    @rmdir(dirname($incomingFile));

                    $done++;
                    $entry = ['id' => $id, 'ok' => true];
                    if ($genReason === 'too-large') {
                        $entry['note'] = '原图过大，已跳过缩略图（原图正常入库）';
                    }
                    $results[] = $entry;
                } catch (\Throwable $e) {
                    $this->discardThumbs($gen); // 防临时缩略图泄漏
                    $failed++;
                    $err = mb_substr($e->getMessage(), 0, 480);
                    try {
                        $pdo->prepare("UPDATE `images` SET `process_status` = 'failed', `process_error` = ? WHERE `id` = ?")
                            ->execute([$err, $id]);
                    } catch (\Throwable) { /* 忽略 */ }
                    $results[] = ['id' => $id, 'error' => $err];
                }
            }

        }

        // remaining 只计 pending —— 它是前端循环的终止条件；failed 需人工
        // 显式重试（requeueFailed），若并入 remaining 会导致循环永不终止。
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'pending'"
        )->fetchColumn();
        $failedLeft = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'failed'"
        )->fetchColumn();

        // v1.3.3-beta.1: 附带实时统计 —— 前端每批据此刷新状态卡片（此前卡片是
        // 服务端一次性渲染的静态值，处理过程中完全不动）。
        $count = static function (string $where) use ($pdo): int {
            return (int) $pdo->query("SELECT COUNT(*) FROM `images`" . ($where !== '' ? " WHERE {$where}" : ''))->fetchColumn();
        };
        $stats = [
            'pending'    => $remaining,
            'processing' => $count("process_status = 'processing'"),
            'done'       => $count("process_status = 'done'"),
            'failed'     => $failedLeft,
            'no_thumb'   => $count("process_status = 'done' AND " . \App\Models\Image::thumbsIncompleteSql()),
            'total'      => $total,
        ];

        $this->json([
            'success' => true,
            'total' => $total,
            'remaining' => $remaining,
            'failed_left' => $failedLeft,
            'done' => $done,
            'failed' => $failed,
            'skipped' => $skipped,
            'stats' => $stats,
            'results' => $results,
        ]);
    }

    /**
     * POST /admin/images/backfill-thumbs —— 补全历史图片**多尺寸**缩略图（分批，幂等）。
     *
     * 判据：process_status='done' AND Image::thumbsIncompleteSql()（缺缩略图或缺字节数）
     * —— 覆盖两类存量行：① 完全无缩略图；② 只有单档 md（本迭代前生成）。
     *
     * 关键：**不改变 process_status**。存量图已在线上展示，若置回 pending 会因
     * 前台过滤条件（process_status='done'）而全部暂时下架 —— 等于自伤事故。
     * 本流程从「最终存储」取回原图，一次解码生成 sm/md/lg 三档并回填
     * thumb_path + thumbs，图片始终可见。回填后 thumbs 恒非空，天然幂等。
     */
    public function backfillThumbs(Request $request): void
    {
        $this->validateCsrf();
        $this->jsonFatalGuard('backfill-thumbs');   // 同上（同样做 GD 解码）
        $batchSize = max(1, min(10, (int) $request->input('batch', '3')));

        // 环境守卫：无 GD/webp 时直接返回错误（避免逐张失败与前端空转）
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->json(['success' => false, 'error' => 'PHP GD 或 WebP 支持不可用，无法生成缩略图（请安装/启用 gd 扩展的 webp 支持）'], 500);
            return;
        }

        // v1.4.0-beta.2: 总开关关闭时拒绝补全 —— 否则会把所有行标记为"已处理"，
        // 重新开启后需要人工重置才能再补，得不偿失。
        if (!self::thumbsEnabled()) {
            $this->json(['success' => false, 'error' => '缩略图生成已在「系统设置 → 图片与存储」中关闭，请先开启后再补全'], 400);
            return;
        }

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        // 判据 = thumbs 列空（含"只有 md、缺 sm/lg"的存量行）。选中的行处理完
        // 会写入非空 JSON（至少 {"ok":1}），因此天然幂等、不会被重复选中。
        // 判据来自模型（单一来源，见 Image::thumbsIncompleteSql）
        $missingCond = \App\Models\Image::thumbsIncompleteSql();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$missingCond}"
        )->fetchColumn();

        $done = 0;
        $failed = 0;
        $results = [];

        if ($remaining > 0) {
            $rows = $pdo->query(
                "SELECT * FROM `images` WHERE process_status = 'done' AND {$missingCond} ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                $gen = null; // v1.3.2-beta.2: catch 里用于清理未及上传的临时缩略图
                try {
                    // v1.4.0-beta.2 性能: 走请求内缓存（原先每行查一次 → N+1）
                    $profile = $this->resolveProfile(
                        $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                    );
                    if ($profile === null) {
                        throw new \RuntimeException('无可用存储实例');
                    }
                    $driver = $profile->driver();

                    // v1.5.0-beta.2: 已有缩略图、只缺字节数 → **只实测，不重做**。
                    // 不重新编码、不覆盖对象（缩略图本身是好的），只问存储要对象长度。
                    // 字节数无法推导（取决于质量设置与 libwebp 版本），但对象长度可以直接问。
                    $haveThumbs = \App\Models\Image::decodeThumbMap(
                        (string) $row['thumbs'],
                        (string) $row['thumb_path']
                    );
                    if ($haveThumbs !== []) {
                        $bytes = [];
                        foreach ($haveThumbs as $size => $key) {
                            $n = $driver->size($key);
                            if ($n !== null && $n > 0) {
                                $bytes[$size] = $n;
                            }
                        }
                        $pdo->prepare("UPDATE `images` SET `thumb_bytes` = ? WHERE `id` = ?")
                            ->execute([\App\Models\Image::encodeThumbBytes($bytes), $id]);
                        $done++;
                        $results[] = [
                            'id'   => $id,
                            'ok'   => true,
                            'note' => '已实测缩略图字节数（' . count($bytes) . '/' . count($haveThumbs) . ' 档）',
                        ];
                        continue;
                    }

                    // —— 从最终存储取回原图字节 ——
                    $localFile = null;
                    $isTemp = false;
                    if ($driver instanceof \App\Storage\LocalDriver) {
                        $localFile = $driver->uploadDir() . '/' . ltrim($path, '/');
                        if (!is_file($localFile) || !is_readable($localFile)) {
                            throw new \RuntimeException('原图文件不存在（' . $path . '）');
                        }
                    } else {
                        $localFile = \App\Storage\S3Driver::downloadUrl($driver->url($path));
                        if ($localFile === null) {
                            throw new \RuntimeException('对象存储取回失败');
                        }
                        $isTemp = true;
                    }

                    // —— 多尺寸缩略图生成 + 逐档上传 ——
                    $gen = $this->makeThumbnails($localFile, (string) $row['mime_type'], $path);
                    if ($isTemp) {
                        @unlink($localFile);
                    }
                    $thumbUp = $this->uploadThumbs($driver, $gen['thumbs']);
                    $thumbKeys = $thumbUp['keys'];

                    // —— 仅回填缩略图（thumb_path 与 thumbs），**状态保持 done** ——
                    // COALESCE 保证未重新生成 md 时不会把已有 thumb_path 抹掉。
                    $pdo->prepare(
                        "UPDATE `images` SET `thumb_path` = COALESCE(?, `thumb_path`), `thumbs` = ?, `thumb_bytes` = ? WHERE `id` = ?"
                    )->execute([
                        $thumbKeys['md'] ?? null,
                        \App\Models\Image::encodeThumbs($thumbKeys),
                        \App\Models\Image::encodeThumbBytes($thumbUp['bytes']),
                        $id,
                    ]);

                    if ($thumbKeys === []) {
                        // 源图小于所有档位（合法空操作）/ 过大（内存预检）/ 不可解码
                        // —— 均已打 ok 标记写入 thumbs，因此不会被重选（幂等）
                        $note = match ((string) ($gen['reason'] ?? '')) {
                            'too-large'      => '原图过大，已跳过缩略图（原图不受影响）',
                            'undecodable'    => '源图不可解码，已标记跳过',
                            'gd-unavailable' => 'GD/WebP 不可用，已标记跳过',
                            default          => '源图小于所有档位，已标记跳过',
                        };
                        $results[] = ['id' => $id, 'ok' => true, 'note' => $note];
                        $done++;
                        continue;
                    }

                    $done++;
                    $results[] = ['id' => $id, 'ok' => true];
                } catch (\Throwable $e) {
                    $this->discardThumbs($gen); // 防临时缩略图泄漏
                    $failed++;
                    $results[] = ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)];
                }
            }

            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$missingCond}"
            )->fetchColumn();
        }

        $this->json([
            'success' => true,
            'total' => $total,
            'remaining' => $remaining,
            'done' => $done,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * GET /admin/images/queue —— 图片处理管理页（状态总览 + 开始/重试）。
     */
    public function queue(Request $request): void
    {
        // v1.4.0-beta.2 增强: 待处理与失败**合并为单一队列**，支持状态筛选 +
        // 文件名/ID 搜索 + 分页。此前是两张各限 50 条的固定表，且失败表为了拿
        // `path` 又逐行查了一次库（50 条 → 50 次查询的 N+1）。
        $status = (string) $request->input('status', 'all');
        if (!in_array($status, ['all', 'pending', 'failed'], true)) {
            $status = 'all';   // 白名单：非法值回落到"全部"
        }
        $search  = trim((string) $request->input('q', ''));
        $page    = max(1, (int) $request->input('page', '1'));
        $perPage = 50;

        $filterVars = [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'pageCount' => 1,
            'perPage' => $perPage,
            'status' => $status,
            'search' => $search,
            'queueTotal' => 0,
        ];

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->render('admin/queue', $filterVars + [
                'error' => '数据库连接失败: ' . $e->getMessage(),
                'stats' => ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'no_thumb' => 0, 'total' => 0],
                'incomingDir' => self::incomingDir(),
            ]);
            return;
        }

        $count = function (string $where) use ($pdo): int {
            return (int) $pdo->query("SELECT COUNT(*) FROM `images`" . ($where !== '' ? " WHERE {$where}" : ''))->fetchColumn();
        };

        $stats = [
            'pending'    => $count("process_status = 'pending'"),
            'processing' => $count("process_status = 'processing'"),
            'done'       => $count("process_status = 'done'"),
            'failed'     => $count("process_status = 'failed'"),
            'no_thumb'   => $count("process_status = 'done' AND " . \App\Models\Image::thumbsIncompleteSql()),
            'total'      => $count(''),
        ];

        // —— 过滤条件：全部走白名单 + 参数绑定（无拼接注入面）——
        $conds  = ["`process_status` IN ('pending','failed')"];
        $params = [];
        if ($status !== 'all') {
            $conds[]  = "`process_status` = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            // % _ \ 必须转义，否则用户输入的 % 会变成通配符（搜"全部"）
            $like = '%' . addcslashes($search, '%_\\') . '%';
            if (ctype_digit($search)) {
                $conds[]  = "(`id` = ? OR `original_name` LIKE ?)";
                $params[] = (int) $search;
                $params[] = $like;
            } else {
                $conds[]  = "`original_name` LIKE ?";
                $params[] = $like;
            }
        }
        $where = implode(' AND ', $conds);

        $cntSt = $pdo->prepare("SELECT COUNT(*) FROM `images` WHERE {$where}");
        $cntSt->execute($params);
        $total = (int) $cntSt->fetchColumn();
        $pageCount = max(1, (int) ceil($total / $perPage));
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $offset = ($page - 1) * $perPage;

        $st = $pdo->prepare(
            "SELECT `id`, `path`, `original_name`, `file_size`, `process_status`, `process_error`, `created_at`"
            . " FROM `images` WHERE {$where} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $incomingDir = self::incomingDir();
        foreach ($rows as &$r) {
            // path 已在同一查询中取出 —— 无需为每行再查一次库
            $p = (string) ($r['path'] ?? '');
            $r['temp_exists'] = $p !== '' && is_file($incomingDir . '/' . ltrim($p, '/'));
        }
        unset($r);

        $this->render('admin/queue', [
            'error' => '',
            'stats' => $stats,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'perPage' => $perPage,
            'status' => $status,
            'search' => $search,
            'queueTotal' => $stats['pending'] + $stats['failed'],
            'incomingDir' => $incomingDir,
        ]);
    }

    /** POST /admin/images/requeue-failed —— 把 failed 的图片重置回 pending 重试。 */
    public function requeueFailed(Request $request): void
    {
        $this->validateCsrf();
        try {
            $pdo = \App\Core\Database::getInstance();
            $n = $pdo->exec("UPDATE `images` SET `process_status` = 'pending' WHERE `process_status` = 'failed'");
            $this->json(['success' => true, 'requeued' => (int) $n]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * v1.3.3-beta.2 增强: GET /admin/images/queue-stats —— 队列实时统计快照（只读）。
     *
     * 供队列页轮询（pending/processing > 0 时每 5 秒刷新一次）更新状态卡片。
     * 轻量端点：不挑选、不处理任何行，无副作用。
     *
     * v1.4.0-beta.2: 不再返回 failed_rows —— 队列已合并为服务端渲染的单一列表
     * （带筛选/搜索/分页），前端不再消费该字段。它此前每 5 秒都要多跑一次
     * 20 行的 SELECT 并传输，属纯冗余。
     */
    public function queueStats(Request $request): void
    {
        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $count = static function (string $where) use ($pdo): int {
            return (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE {$where}")->fetchColumn();
        };
        $stats = [
            'pending'    => $count("process_status = 'pending'"),
            'processing' => $count("process_status = 'processing'"),
            'done'       => $count("process_status = 'done'"),
            'failed'     => $count("process_status = 'failed'"),
            'no_thumb'   => $count("process_status = 'done' AND " . \App\Models\Image::thumbsIncompleteSql()),
            'total'      => (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn(),
        ];

        $this->json(['success' => true, 'stats' => $stats]);
    }

    /**
     * v1.5.0-beta.1 存储结构统一（方案 A）: POST /admin/images/migrate-layout
     *
     * 把旧布局对象迁移到统一布局：
     *   旧  {yyyy}/{mm}/{uuid}.{ext}                     + thumbs/…（md 无尺寸段）
     *   新  {yyyy}/{mm}/{uuid}/original.{ext}            + {dir}/thumb-{size}.webp
     *
     * 逐资产原子 + 三阶段（顺序是关键）：
     *   1. **复制**：每个对象取回字节 → 写入新键 → 校验新键存在（此时旧对象未删、DB 未改）
     *   2. **改库**：DB 指向新键（此刻新键已全部校验存在 → 不存在"DB 指向缺失对象"的窗口）
     *   3. **删旧**：尽力删除旧键；失败只留下无害孤立对象，不影响任何可用性
     *   任一阶段失败 → 回滚本次已上传的新对象，旧对象与 DB 均保持原样，该行仍完全可用。
     *
     * 其他要点：
     *   - mode=dry-run（默认）只返回迁移计划，不写对象、不改 DB
     *   - 幂等：判据是 path 形态，已迁移的行不会被再次选中
     *   - 未处理的行（pending/failed）原图只在本地临时目录 → 同盘 rename 即可，无需上传
     */
    public function migrateLayout(Request $request): void
    {
        $this->validateCsrf();
        // v1.5.0-beta.2: 迁移会逐行复制对象（含网络往返），同样是长任务 —— 致命错误
        // 必须变成可读 JSON（此前三个阶段都没有守卫，出事只能看到空响应）。
        $this->jsonFatalGuard('migrate-layout');
        $mode = (string) $request->input('mode', 'dry-run');
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            $this->json(['success' => false, 'error' => '无效的迁移模式'], 400);
            return;
        }
        $batch = max(1, min(20, (int) $request->input('batch', '3')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $legacyWhere = "`path` IS NOT NULL AND `path` <> '' AND `path` NOT LIKE '%/original.%'";
        $legacyTotal = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE {$legacyWhere}")->fetchColumn();
        $newTotal    = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE `path` LIKE '%/original.%'")->fetchColumn();

        $rows = $pdo->query(
            "SELECT `id`, `path`, `thumbs`, `thumb_path`, `mime_type`, `storage_profile_id`, `process_status`"
            . " FROM `images` WHERE {$legacyWhere} ORDER BY `id` ASC LIMIT {$batch}"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $processed = 0;
        $migrated = 0;
        $failed = 0;
        $skipped = 0;
        $plan = [];
        $results = [];

        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $oldPath = (string) $row['path'];
            $parts   = \App\Models\Image::assetParts($oldPath);
            $newPath = $parts['dir'] . '/original.' . ($parts['ext'] !== '' ? $parts['ext'] : 'bin');

            $thumbMap = \App\Models\Image::decodeThumbMap(
                (string) ($row['thumbs'] ?? ''),
                (string) ($row['thumb_path'] ?? '')
            );
            $newThumbs = [];
            $moves = [['old' => $oldPath, 'new' => $newPath, 'required' => true, 'mime' => (string) ($row['mime_type'] ?? '')]];
            foreach ($thumbMap as $size => $oldKey) {
                $newKey = \App\Models\Image::thumbKey($size, $newPath);   // 新布局规则
                $newThumbs[$size] = $newKey;
                if ($oldKey !== $newKey) {
                    $moves[] = ['old' => $oldKey, 'new' => $newKey, 'required' => false, 'mime' => 'image/webp'];
                }
            }

            $processed++;

            if ($mode === 'dry-run') {
                $plan[] = [
                    'id'   => $id,
                    'from' => array_column($moves, 'old'),
                    'to'   => array_column($moves, 'new'),
                ];
                continue;
            }

            $driver = null;
            $created = [];
            try {
                $profile = $this->resolveProfile($row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null);
                $driver = $profile?->driver();
                if ($driver === null) {
                    throw new \RuntimeException('无可用存储实例');
                }

                // 未处理完成的行：原图仍只在本地临时目录（云端尚未上传）。
                // 本地存储可直接把临时文件改名到新键；云端则**跳过**（等处理完成后再迁移），
                // 计为 skipped 而非失败，避免把"还没轮到它"误报成错误。
                if ((string) $row['process_status'] !== 'done' && !($driver instanceof \App\Storage\LocalDriver)) {
                    $skipped++;
                    $results[] = ['id' => $id, 'skipped' => true, 'note' => '尚未处理完成（对象未上传到存储），处理完成后可再次迁移'];
                    continue;
                }

                // 阶段 1：复制 + 校验（旧对象不删、DB 不改）
                foreach ($moves as $mv) {
                    $newKey = $this->copyObjectWithinDriver(
                        $driver,
                        $mv['old'],
                        $mv['new'],
                        $mv['mime'],
                        (bool) $mv['required']
                    );
                    if ($newKey !== null) {
                        $created[] = $newKey;
                    }
                }

                // 阶段 2：DB 指向新键
                $pdo->prepare(
                    "UPDATE `images` SET `path` = ?, `thumbs` = ?, `thumb_path` = ? WHERE `id` = ?"
                )->execute([
                    $newPath,
                    \App\Models\Image::encodeThumbs($newThumbs),
                    $newThumbs['md'] ?? null,
                    $id,
                ]);

                // 阶段 3：删旧（尽力而为）
                $orphans = [];
                foreach ($moves as $mv) {
                    if ($mv['old'] === $mv['new']) {
                        continue;
                    }
                    if (!$driver->delete($mv['old'])) {
                        $orphans[] = $mv['old'];
                    }
                }

                $migrated++;
                $results[] = [
                    'id' => $id,
                    'ok' => true,
                    'moved' => count($moves),
                    'orphans' => $orphans,   // 非空表示旧对象删除失败（孤立但无害）
                ];
            } catch (\Throwable $e) {
                // 回滚阶段 1 已创建的新对象；旧对象与 DB 均未改动 → 该行仍可用
                foreach ($created as $key) {
                    if ($driver !== null) {
                        $driver->delete($key);
                    }
                }
                $failed++;
                $results[] = ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        $remaining = max(0, $legacyTotal - $migrated);

        $this->json([
            'success' => true,
            'mode' => $mode,
            'legacy_total' => $legacyTotal,
            'new_total' => $newTotal,
            'processed' => $processed,
            'migrated' => $migrated,
            'failed' => $failed,
            'skipped' => $skipped,
            'remaining' => $mode === 'apply' ? $remaining : $legacyTotal,
            'plan' => $plan,
            'results' => $results,
        ]);
    }

    /**
     * 在**同一驱动内**把对象从 $oldKey 复制/移动到 $newKey，复制后校验新键存在。
     *
     * - 本地存储：最终存储里有文件 → 直接上传（同盘复制）；否则看临时目录
     *   （未处理的行）→ 同盘 rename 即可，无需上传
     * - 云端：$required=false 时先用 exists() 判存在（避免无谓下载）；取回字节后上传
     *
     * @param bool $required 源对象缺失时是否视为致命
     * @return ?string 新创建的对象键（无对象创建时为 null，例如临时文件直接改名）
     */
    private function copyObjectWithinDriver(
        \App\Storage\StorageInterface $driver,
        string $oldKey,
        string $newKey,
        string $mime,
        bool $required
    ): ?string {
        if ($oldKey === '' || $oldKey === $newKey) {
            return null;
        }

        $src = null;
        $isTemp = false;

        if ($driver instanceof \App\Storage\LocalDriver) {
            $finalFile = $driver->uploadDir() . '/' . ltrim($oldKey, '/');
            if (is_file($finalFile)) {
                $src = $finalFile;
            } else {
                // 未处理的行：原图只在 storage/incoming，同盘 rename（无需上传）
                $tempFile = self::incomingDir() . '/' . ltrim($oldKey, '/');
                if (!is_file($tempFile)) {
                    if (!$required) {
                        return null;
                    }
                    throw new \RuntimeException('对象不存在: ' . $oldKey);
                }
                $dst = self::incomingDir() . '/' . ltrim($newKey, '/');
                if (!is_dir(dirname($dst))) {
                    @mkdir(dirname($dst), 0755, true);
                }
                if (!@rename($tempFile, $dst)) {
                    throw new \RuntimeException('临时文件移动失败: ' . $oldKey);
                }
                @rmdir(dirname($tempFile));
                return null;   // 只是改名，没有"新对象"需要回滚
            }
        } else {
            if (!$driver->exists($oldKey)) {
                if (!$required) {
                    return null;   // 该档缩略图缺失 → 后续可重新生成
                }
                throw new \RuntimeException('对象不存在: ' . $oldKey);
            }
            $src = \App\Storage\S3Driver::downloadUrl($driver->url($oldKey));
            $isTemp = true;
        }

        if ($src === null) {
            if (!$required) {
                return null;
            }
            throw new \RuntimeException('对象取回失败: ' . $oldKey);
        }

        try {
            $driver->upload($src, $newKey, $mime !== '' ? $mime : 'application/octet-stream');
            if (!$driver->exists($newKey)) {
                throw new \RuntimeException('新对象校验失败: ' . $newKey);
            }
        } finally {
            if ($isTemp) {
                @unlink($src);
            }
        }

        return $newKey;
    }

    /**
     * v1.3.3-beta.2 增强: POST /admin/images/requeue-one —— 重试**单张**失败的图片。
     *
     * 与 requeueFailed（全部失败项）互补：失败明细面板里逐张操作。
     * 安全边界：只允许把 failed 行重置回 pending —— done/processing 行打不回去，
     * 防误触或恶意把已发布图片打回隐藏态。
     */
    public function requeueOne(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id', '0');
        if ($id <= 0) {
            $this->json(['success' => false, 'error' => '缺少有效的图片 id'], 400);
            return;
        }
        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }
        $st = $pdo->prepare(
            "UPDATE `images` SET `process_status` = 'pending', `process_error` = NULL"
            . " WHERE `id` = ? AND `process_status` = 'failed'"
        );
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            $this->json(['success' => false, 'error' => '该图片不是失败状态（可能已处理完成或不存在）'], 409);
            return;
        }
        $this->json(['success' => true, 'requeued' => 1, 'id' => $id]);
    }

    /* ------------------------------------------------------------------
     * v1.5.0-beta.1: 历史原图转 WebP（干跑优先）
     * ------------------------------------------------------------------ */

    /** 每批处理的行数上限（历史原图转换；云端要逐个下载+上传，批量不宜大）。 */
    private const CONVERT_BATCH_MAX = 20;

    /**
     * 干跑（候选筛查）的每批行数上限。
     *
     * v1.5.0-beta.2：干跑改为**零 I/O 的纯筛查**（只判格式与键名，不下载不解码），
     * 因此批次可以大得多 —— 这样才能在合理轮次内扫完整个表。此前干跑与执行共用
     * LIMIT 5，且只在"第一批"上做判断，表里前 5 行恰好都是 GIF/SVG 时就会报
     * "待转 0 张"（与清理残留同源的误报：0 表示"没看"，不是"没有"）。
     */
    private const CONVERT_SCAN_BATCH_MAX = 500;

    /** 可转 WebP 的源格式（其余一律跳过：GIF 会丢动画、SVG 是矢量）。 */
    private const CONVERTIBLE_MIMES = ['image/jpeg', 'image/png', 'image/bmp', 'image/avif'];

    /**
     * POST /admin/images/convert-originals —— 把历史原图批量转成 WebP（干跑优先）。
     *
     * v1.5.0-beta.1 之前上传的原图保持原格式（当时只有缩略图转 WebP）。本工具按行
     * 推进、可中断、可续跑（游标 settings.original_convert_cursor，**只在 apply 时
     * 推进**）：
     *
     *   取字节 → 转码（与上传路径同一个 convertOriginalToWebp）→ 上传新键 →
     *   **校验新对象存在** → 更新记录（path/mime/size/双哈希）→ 删除旧对象
     *
     * 键怎么变：**只换扩展名、布局不动** —— 新布局 `{dir}/original.{ext}` →
     * `{dir}/original.webp`；旧布局 `{Y}/{m}/{uuid}.{ext}` → `{Y}/{m}/{uuid}.webp`。
     * 两种布局下**缩略图键都与原图扩展名无关**（新布局是同目录固定名 `thumb-{size}.webp`，
     * 旧布局是 `thumbs/…/{uuid}.webp`），所以缩略图完全不需要搬迁。
     *
     * 安全护栏（任一不满足即回滚该行、保持原样）：
     *   - 只处理 process_status='done' 且 path 非 .webp 的行；
     *   - 源格式必须在 CONVERTIBLE_MIMES（GIF/SVG 连字节都不必拉）；
     *   - 转码不成功/不更小 → 跳过（不换键、不动旧对象）；
     *   - **新对象上传后先 exists() 校验，再改库** —— 任何时刻记录都指向存在的对象；
     *   - 改库失败 → 删掉新对象回滚（旧对象与记录原封不动）；
     *   - 删旧失败只记 orphan 风险（新对象已就位，服务不受影响）。
     *
     * 注意：转换会**重算 file_hash/file_sha256**（记录的哈希必须描述实际存储的字节）。
     * 云端每行都要下载+上传，耗时可观 —— 建议先干跑看数量，再分批执行。
     */
    public function convertOriginals(Request $request): void
    {
        $this->validateCsrf();
        // v1.5.0-beta.2（线上故障修复）: 致命错误必须变成**可读 JSON**，而不是空响应。
        // 此前本端点没有守卫（6 个重型端点里只有 processQueue / backfillThumbs 有）→
        // 大图解码 OOM 或超时时，前端只看到"服务器返回空响应（HTTP 500）"，
        // 既不知道原因，也不知道卡在哪一行。守卫同时提升内存与时间上限。
        $this->jsonFatalGuard('convert-originals');

        $mode = (string) $request->input('mode', 'dry-run');
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            $this->json(['success' => false, 'error' => '无效的转换模式'], 400);
            return;
        }
        $apply = $mode === 'apply';

        // 干跑是**零 I/O 的候选筛查**（不下载、不解码、不写任何文件）→ 批次可以大得多；
        // 执行转换每行都要下载 + 转码 + 上传，保持小批次。
        $batchMax = $apply ? self::CONVERT_BATCH_MAX : self::CONVERT_SCAN_BATCH_MAX;
        $batch = max(1, min($batchMax, (int) $request->input('batch', $apply ? '5' : '200')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        // 起点：干跑由前端显式给 from（**每轮从表头开始**，幂等重扫，避免"游标已越过"漏扫）；
        // 执行转换沿用持久化游标，可中断、可续跑。
        $fromRaw = $request->input('from', null);
        $fromId  = ($fromRaw === null || $fromRaw === '') ? null : max(0, (int) $fromRaw);
        $cursor  = $fromId ?? ($apply ? (int) \App\Models\Setting::get('original_convert_cursor', '0') : 0);

        $stmt = $pdo->prepare(
            "SELECT `id`, `path`, `mime_type`, `storage_profile_id` FROM `images`"
            . " WHERE `id` > ? AND `process_status` = 'done' AND `path` <> '' AND `path` NOT LIKE '%.webp'"
            . " ORDER BY `id` ASC LIMIT {$batch}"
        );
        $stmt->execute([$cursor]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $scanned = 0;
        $planned = 0;
        $converted = 0;
        $skipped = 0;
        $failed = 0;
        $orphanRisk = 0;
        $notes = [];
        $samples = [];
        $lastId = $cursor;

        foreach ($rows as $row) {
            $scanned++;
            $id = (int) $row['id'];
            $lastId = max($lastId, $id);
            $path = (string) $row['path'];
            $mime = (string) ($row['mime_type'] ?? '');

            if (!in_array($mime, self::CONVERTIBLE_MIMES, true)) {
                $skipped++;
                $notes['源格式不支持'] = ($notes['源格式不支持'] ?? 0) + 1;
                continue;
            }

            // 只换扩展名、布局不动 —— 也正因如此缩略图键不受影响（廉价检查放在重活之前）
            $newPath = (string) preg_replace('/\.[a-z0-9]+$/i', '.webp', $path);
            if ($newPath === $path || $newPath === '') {
                $skipped++;
                $notes['键名无法改写'] = ($notes['键名无法改写'] ?? 0) + 1;
                continue;
            }

            if (!$apply) {
                // 干跑：**零 I/O**。不下载、不解码、不编码、不写任何文件 ——
                //   ① 干跑绝不能改动文件：此前本地驱动把真实原图传进转码函数，
                //      而旧实现是"原地覆盖"，于是干跑真的把原图换成 WebP、记录却不改；
                //   ② 干跑若也下载 + 解码，大图会 OOM，"看下数量"这种轻操作反而致命；
                //   ③ 所以"是否真的更小"只能在执行时逐张判定 —— 这里的数字是
                //      **候选数**，面板文案如实这么写，不谎称是精确的待转数。
                $planned++;
                if (count($samples) < 12) {
                    $samples[] = ['id' => $id, 'from' => $path, 'to' => $newPath];
                }
                continue;
            }

            $srcCopy = null;
            $destCopy = null;
            $driver = null;
            $uploadedNew = null;
            try {
                $profile = $this->resolveProfile(
                    $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                );
                $driver = $profile?->driver();
                if ($driver === null) {
                    $failed++;
                    $notes['存储实例不可用'] = ($notes['存储实例不可用'] ?? 0) + 1;
                    continue;
                }

                // —— 取字节到**私有副本**：本地也复制一份，绝不在真实存储文件上转码 ——
                if ($driver instanceof \App\Storage\LocalDriver) {
                    $stored = $driver->uploadDir() . '/' . ltrim($path, '/');
                    if (!is_file($stored) || !is_readable($stored)) {
                        $failed++;
                        $notes['源对象不可读'] = ($notes['源对象不可读'] ?? 0) + 1;
                        continue;
                    }
                    $srcCopy = $this->tempConvertPath($id);
                    if (!@copy($stored, $srcCopy)) {
                        $failed++;
                        $notes['副本创建失败'] = ($notes['副本创建失败'] ?? 0) + 1;
                        continue;
                    }
                } else {
                    $srcCopy = \App\Storage\S3Driver::downloadUrl($driver->url($path));
                    if ($srcCopy === null || !is_file($srcCopy)) {
                        $failed++;
                        $notes['对象拉取失败'] = ($notes['对象拉取失败'] ?? 0) + 1;
                        continue;
                    }
                }

                // —— 内存预算（原图专用口径：含 EXIF 旋转的第二块画布与原始字节）——
                // 超预算就跳过后如实计数，**绝不让它变成不可捕获的 OOM**
                $budget = $this->originalConvertBudget($srcCopy, $mime);
                if ($budget !== null) {
                    $skipped++;
                    $key = $budget['code'] === 'memory-budget' ? '超出内存预算（跳过）' : '尺寸不可读（跳过）';
                    $notes[$key] = ($notes[$key] ?? 0) + 1;
                    if (count($samples) < 12) {
                        $samples[] = ['id' => $id, 'note' => $budget['detail']];
                    }
                    continue;
                }

                $destCopy = $this->tempConvertPath($id);
                $conv = $this->convertOriginalToWebp($srcCopy, $mime, $destCopy);
                if (!$conv['ok']) {
                    $skipped++;
                    $reason = $conv['note'] === 'not-smaller' ? '转换后未更小（保留原图）' : ('未转换：' . $conv['note']);
                    $notes[$reason] = ($notes[$reason] ?? 0) + 1;
                    continue;
                }

                // —— 上传新键 → 校验 → 改库 → 删旧（任一步失败即回滚）——
                $driver->upload($destCopy, $newPath, 'image/webp');
                $uploadedNew = $newPath;
                if (!$driver->exists($newPath)) {
                    throw new \RuntimeException('新对象上传后校验失败');
                }

                $bytes = (int) ($conv['bytes'] ?? 0);
                if ($bytes <= 0) {
                    $bytes = (int) @filesize($destCopy);
                }
                $md5 = @hash_file('md5', $destCopy);
                $sha = @hash_file('sha256', $destCopy);

                $upd = $pdo->prepare(
                    "UPDATE `images` SET `path` = ?, `filename` = ?, `mime_type` = 'image/webp',"
                    . " `file_size` = ?, `file_hash` = ?, `file_sha256` = ?, `url` = '' WHERE `id` = ?"
                );
                if (!$upd->execute([
                    $newPath,
                    basename($newPath),
                    $bytes,
                    ($md5 === false || $md5 === '') ? null : $md5,
                    ($sha === false || $sha === '') ? null : $sha,
                    $id,
                ])) {
                    throw new \RuntimeException('数据库更新失败');
                }

                // 旧对象删除失败不影响服务（新对象已就位）—— 如实计入 orphan 风险
                if (!$driver->delete($path)) {
                    $orphanRisk++;
                }

                $converted++;
                if (count($samples) < 12) {
                    $samples[] = ['id' => $id, 'from' => $path, 'to' => $newPath, 'action' => 'converted'];
                }
            } catch (\Throwable $e) {
                $failed++;
                // 回滚：已上传的新对象必须删掉，否则记录仍指向旧对象、新对象白留一份
                if ($uploadedNew !== null && $driver !== null) {
                    @$driver->delete($uploadedNew);
                }
                if (count($samples) < 12) {
                    $samples[] = ['id' => $id, 'note' => '异常：' . $e->getMessage()];
                }
            } finally {
                // 两个副本都是我们自己创建的（本地复制的 / 云端下载的），一律清理 ——
                // 真实存储对象从头到尾没有被当作工作文件使用过。
                foreach ([$srcCopy, $destCopy] as $tmp) {
                    if (is_string($tmp) && $tmp !== '' && is_file($tmp)) {
                        @unlink($tmp);
                    }
                }
            }
        }

        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE `id` > " . (int) $lastId
            . " AND `process_status` = 'done' AND `path` <> '' AND `path` NOT LIKE '%.webp'"
        )->fetchColumn();

        if ($apply && $scanned > 0) {
            \App\Models\Setting::set('original_convert_cursor', (string) $lastId);
        }

        $this->json([
            'success'    => true,
            'mode'       => $mode,
            'scanned'    => $scanned,
            'planned'    => $planned,
            'converted'  => $converted,
            'skipped'    => $skipped,
            'failed'     => $failed,
            'orphan_risk' => $orphanRisk,
            'remaining'  => $remaining,
            'next_from'  => $lastId,
            'notes'      => $notes,
            'samples'    => $samples,
        ]);
    }

    /* ------------------------------------------------------------------
     * v1.5.0-beta.1: 存储残留清理（干跑优先）
     * ------------------------------------------------------------------ */

    /** 每批处理的条目上限（行 / 文件），防止一次请求做太多删除。 */
    private const CLEANUP_BATCH_MAX = 50;

    /**
     * POST /admin/images/cleanup-storage —— 清理历次更新留下的存储残留（干跑优先）。
     *
     * 只清理三类**能被确定性判定**的残留：
     *   ① 旧布局对象：行的 path 已是新布局，但按旧规则对应的键仍留在存储里
     *      （迁移工具"尽力删旧"失败、或曾经手工迁移过）。
     *   ② 暂存垃圾：storage/incoming 下不再属于 pending/processing/failed 行的
     *      临时文件（致命错误、清空队列等留下的）。
     *   ③ 历史媒体根残留：public/uploads 下**没有任何记录引用**的文件
     *      （品牌 logo 目录永不触碰）。
     *
     * 为什么不做"孤立对象扫描"：`StorageInterface` 没有 LIST 能力，无法枚举桶内
     * 对象 —— **记录已被删除**的孤立对象（`queueClear` 的 orphan_risk）依旧无法
     * 被发现，只能人工处理。UI 与文档都如实说明，不假装能清干净。
     *
     * 安全护栏（任一不满足就跳过该条，绝不删）：
     *   - 只删"确定没有被任何记录引用"的对象/文件；
     *   - 删旧布局对象前，先确认该行**当前的原图对象确实存在**（否则旧对象可能是
     *     唯一副本 —— 宁可留下垃圾也不赌）；
     *   - 待删键不得等于该行当前记录的任何键；
     *   - 暂存文件对应的行若处于 pending/processing/failed 则保留（可能正在处理）；
     *   - 品牌 logo 目录（public/uploads/logo）永不触碰。
     *
     * `mode=dry-run`（默认）只出清单、**不写任何状态**；`apply` 才真正删除并推进游标。
     *
     * 干跑可以**全表扫描**：前端按批携带 `from`（首轮 0，随后用响应里的 next_from），
     * 因此干跑不受持久化游标影响 —— 这点是修过的：此前干跑只扫一批，行数一多就会
     * 给出"待清理 0 项"的错误结论（其实是"没看"）。
     *
     * 响应额外带 `diagnostics`（含"为什么没有可清理项"的结论 `verdict`），
     * 供操作员判断能否在对象存储控制台整删 `thumbs/`。
     */
    public function cleanupStorage(Request $request): void
    {
        $this->validateCsrf();
        // v1.5.0-beta.2: 与 convert-originals 同理 —— 重型端点必须把致命错误
        // （OOM / 超时）变成可读 JSON，而不是让前端只看到"空响应（HTTP 500）"
        // （清理会遍历公共目录，文件极多时属于长任务）。
        $this->jsonFatalGuard('cleanup-storage');
        $mode = (string) $request->input('mode', 'dry-run');
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            $this->json(['success' => false, 'error' => '无效的清理模式'], 400);
            return;
        }
        $apply = $mode === 'apply';
        $batch = max(1, min(self::CLEANUP_BATCH_MAX, (int) $request->input('batch', '10')));
        // 扫描起点：客户端可用 from 显式指定（干跑即用此法从 0 开始逐批推进 → **全表扫描**）。
        // 缺省沿用持久化游标（apply 的续跑语义）。此前干跑只跑一批，行数多时会给出
        // "待清理 0 项"的错误结论 —— 那是"没看"而不是"没有"。
        $fromRaw = $request->input('from', null);
        $fromId = ($fromRaw === null || $fromRaw === '') ? null : max(0, (int) $fromRaw);
        // 本地目录（暂存 / 历史根）与行游标无关，前端只在首轮请求，避免重复计数。
        $scope = (string) $request->input('scope', 'all');

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        try {
            $objects = $this->cleanupLegacyObjects($pdo, $batch, $apply, $fromId);
            $zero = ['scanned' => 0, 'deleted' => 0, 'planned' => 0, 'skipped' => 0,
                     'failed' => 0, 'remaining' => 0, 'samples' => []];
            $staging = $scope === 'all' ? $this->cleanupStagingFiles($pdo, $batch, $apply) : $zero;
            $legacy  = $scope === 'all' ? $this->cleanupLegacyRootFiles($pdo, $batch, $apply) : $zero;
            $diag = $this->cleanupDiagnostics($pdo, $objects);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '清理失败: ' . $e->getMessage()], 500);
            return;
        }

        $this->json([
            'success'     => true,
            'mode'        => $mode,
            'objects'     => $objects,
            'staging'     => $staging,
            'legacy_root' => $legacy,
            'diagnostics' => $diag,
            // 下一批的扫描起点（0 表示已到表尾）；干跑靠它逐批推进到全表结束
            'next_from'   => $objects['next_from'],
            'remaining'   => $objects['remaining'] + $staging['remaining'] + $legacy['remaining'],
        ]);
    }

    /**
     * ③ 诊断：把"为什么没有可清理项"讲清楚（此前只回一个 0，操作员无从判断）。
     *
     * 关键判据 `refs_thumbs_prefix`：**仍有多少行引用 `thumbs/` 前缀下的对象**。
     *   - > 0 → 那些对象是活引用，绝不能删（先把这些行迁移到新布局）；
     *   - = 0 → `thumbs/` 下不存在任何被记录引用的对象，桶里若还有东西，就都是
     *           「记录已删除」的孤儿 —— 本工具查不到（存储接口无 LIST），可直接整删。
     *
     * 计数为什么可信：`thumbs` 列是 JSON（`encodeThumbs` 用 JSON_UNESCAPED_SLASHES，
     * 斜杠不转义），`thumb_path` 是纯路径，两者任一含 `thumbs/` 即计入。
     */
    private function cleanupDiagnostics(\PDO $pdo, array $objects): array
    {
        $legacyWhere = "`path` IS NOT NULL AND `path` <> '' AND `path` NOT LIKE '%/original.%'";
        $newRows    = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE `path` LIKE '%/original.%'")->fetchColumn();
        $legacyRows = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE {$legacyWhere}")->fetchColumn();
        $refs       = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE `thumb_path` LIKE 'thumbs/%' OR `thumbs` LIKE '%\"thumbs/%'"
        )->fetchColumn();
        $cursor = (int) \App\Models\Setting::get('storage_cleanup_cursor', '0');

        if ($refs > 0) {
            $verdict = "仍有 {$refs} 行引用 thumbs/ 下的对象 —— 它们正在使用中，不能删。"
                . '请先到「存储结构与迁移」执行「开始迁移」，必要时再「补全历史缩略图」。';
            $canWipe = false;
        } elseif ($objects['planned'] > 0) {
            $verdict = '发现 ' . $objects['planned'] . ' 个已无引用的旧布局对象（属于已迁移的行），点「执行清理」删除即可。';
            $canWipe = true;
        } elseif ($legacyRows > 0) {
            $verdict = "thumbs/ 下没有任何记录引用，可整删（桶里剩余对象均为「记录已删除」的孤儿）。"
                . "注意仍有 {$legacyRows} 行未迁移，它们处理完成后会把缩略图写回 thumbs/，建议先「开始迁移」。";
            $canWipe = true;
        } else {
            $verdict = 'thumbs/ 下没有任何记录引用 —— 桶里若仍有对象，都属于「记录已删除」的孤儿'
                . '（本工具无法列举桶内对象），可直接在对象存储控制台整删。';
            $canWipe = true;
        }

        return [
            'cursor'              => $cursor,
            'new_layout_rows'     => $newRows,
            'legacy_layout_rows'  => $legacyRows,
            'refs_thumbs_prefix'  => $refs,
            'keys_checked'        => (int) ($objects['keys_checked'] ?? 0),
            'keys_existing'       => (int) ($objects['keys_existing'] ?? 0),
            'can_wipe_thumbs'     => $canWipe,
            'verdict'             => $verdict,
        ];
    }

    /**
     * ① 旧布局对象清理（按行推进；游标只在 apply 时持久化，便于分批续跑）。
     *
     * @param int|null $fromId 扫描起点；null = 用持久化游标。干跑由前端按批推进 from，
     *                         从而做到"全表扫描但不写任何状态"。
     */
    private function cleanupLegacyObjects(\PDO $pdo, int $batch, bool $apply, ?int $fromId = null): array
    {
        // 新一轮**始终从表头开始**（清点是幂等的，重扫最多多几次 exists 探测），
        // 这样不会因为"游标已经越过某行"而漏清。批间推进由前端携带 from 完成；
        // settings.storage_cleanup_cursor 仍写回，仅用于观测。
        $cursor = $fromId ?? 0;
        $stmt = $pdo->prepare(
            "SELECT `id`, `path`, `thumbs`, `thumb_path`, `storage_profile_id` FROM `images`"
            . " WHERE `id` > ? AND `path` LIKE '%/original.%' ORDER BY `id` ASC LIMIT {$batch}"
        );
        $stmt->execute([$cursor]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $scanned = 0;
        $deleted = 0;
        $planned = 0;
        $skipped = 0;
        $failed = 0;
        // 诊断计数：本批到底探测了多少个候选旧键、其中多少个确实存在。
        // 二者都为 0 时才能说"这一批确实没有残留"，而不是"没看"。
        $keysChecked = 0;
        $keysExisting = 0;
        $samples = [];
        $lastId = $cursor;

        foreach ($rows as $row) {
            $scanned++;
            $id = (int) $row['id'];
            $lastId = max($lastId, $id);
            $path = (string) $row['path'];

            $parts = \App\Models\Image::assetParts($path);
            if ($parts['layout'] !== \App\Models\Image::LAYOUT_V2) {
                $skipped++;
                continue;
            }

            // 该行当前的键（原图 + 各尺寸）—— 待删键绝不能与它们重合
            $current = [$path => true];
            $thumbMap = \App\Models\Image::decodeThumbMap(
                (string) ($row['thumbs'] ?? ''),
                (string) ($row['thumb_path'] ?? '')
            );
            foreach ($thumbMap as $k) {
                $current[(string) $k] = true;
            }

            try {
                $profile = $this->resolveProfile(
                    $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                );
                $driver = $profile?->driver();
                if ($driver === null) {
                    $skipped++;
                    continue;
                }

                // —— 关键护栏：当前原图对象必须存在，否则旧对象可能是唯一副本 ——
                if (!$driver->exists($path)) {
                    $skipped++;
                    if (count($samples) < 12) {
                        $samples[] = ['id' => $id, 'note' => '当前原图对象不存在，跳过（避免删掉唯一副本）'];
                    }
                    continue;
                }

                $legacyOriginal = dirname($parts['dir']) . '/' . basename($parts['dir']) . '.' . $parts['ext'];
                $candidates = [$legacyOriginal];
                foreach (['md', 'sm', 'lg'] as $size) {
                    // thumbKey() 对旧布局路径即产出旧规则键（与生成端同源）
                    $candidates[] = \App\Models\Image::thumbKey($size, $legacyOriginal);
                }

                foreach ($candidates as $key) {
                    $key = (string) $key;
                    if ($key === '' || isset($current[$key])) {
                        continue;
                    }
                    $keysChecked++;
                    if (!$driver->exists($key)) {
                        continue;
                    }
                    $keysExisting++;
                    if (!$apply) {
                        $planned++;
                        if (count($samples) < 12) {
                            $samples[] = ['id' => $id, 'key' => $key, 'action' => 'would-delete'];
                        }
                        continue;
                    }
                    if ($driver->delete($key)) {
                        $deleted++;
                        if (count($samples) < 12) {
                            $samples[] = ['id' => $id, 'key' => $key, 'action' => 'deleted'];
                        }
                    } else {
                        $failed++;
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                if (count($samples) < 12) {
                    $samples[] = ['id' => $id, 'note' => '异常：' . $e->getMessage()];
                }
            }
        }

        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE `id` > " . (int) $lastId . " AND `path` LIKE '%/original.%'"
        )->fetchColumn();

        if ($apply && $scanned > 0) {
            \App\Models\Setting::set('storage_cleanup_cursor', (string) $lastId);
        }

        return [
            'scanned' => $scanned, 'deleted' => $deleted, 'planned' => $planned,
            'skipped' => $skipped, 'failed' => $failed, 'remaining' => $remaining,
            'keys_checked' => $keysChecked, 'keys_existing' => $keysExisting,
            'next_from' => $lastId,
            'samples' => $samples,
        ];
    }

    /**
     * ② 暂存目录垃圾：storage/incoming 下不再属于"在办"行的临时文件。
     */
    private function cleanupStagingFiles(\PDO $pdo, int $batch, bool $apply): array
    {
        $root = self::incomingDir();
        $result = ['scanned' => 0, 'deleted' => 0, 'planned' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0, 'samples' => []];
        if (!is_dir($root)) {
            return $result;
        }

        $lookup = $pdo->prepare("SELECT `process_status` FROM `images` WHERE `path` = ? LIMIT 1");
        $files = $this->listFilesRecursive($root, $batch * 4);
        $stale = 0;

        foreach ($files as $abs) {
            $result['scanned']++;
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
            $lookup->execute([$rel]);
            $status = $lookup->fetchColumn();

            // 在办的行（含正在处理）一律保留 —— 那个文件可能正被另一个请求使用
            if ($status !== false && in_array((string) $status, ['pending', 'processing', 'failed'], true)) {
                $result['skipped']++;
                continue;
            }

            $stale++;
            if ($stale > $batch) {
                $result['remaining']++;
                continue;
            }
            if (!$apply) {
                $result['planned']++;
                if (count($result['samples']) < 12) {
                    $result['samples'][] = ['path' => $rel, 'action' => 'would-delete'];
                }
                continue;
            }
            if (@unlink($abs)) {
                $result['deleted']++;
                if (count($result['samples']) < 12) {
                    $result['samples'][] = ['path' => $rel, 'action' => 'deleted'];
                }
                $this->pruneEmptyDirs(dirname($abs), $root);
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * ③ 历史媒体根残留：public/uploads 下没有任何记录引用的文件。
     *
     * 注意这条**与迁移状态无关**也安全：只要还有记录引用某个文件就保留 —— 因此
     * 即使尚未执行布局迁移，也不会误删在用的图。
     */
    private function cleanupLegacyRootFiles(\PDO $pdo, int $batch, bool $apply): array
    {
        $root = \App\Storage\LocalDriver::legacyUploadDir();
        $result = ['scanned' => 0, 'deleted' => 0, 'planned' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0, 'samples' => []];
        if (!is_dir($root)) {
            return $result;
        }

        $branding = basename(\App\Storage\LocalDriver::BRANDING_REL_DIR);
        $referenced = $pdo->prepare(
            "SELECT 1 FROM `images` WHERE `path` = ? OR `thumb_path` = ? OR `thumbs` LIKE ? LIMIT 1"
        );
        $files = $this->listFilesRecursive($root, $batch * 4, [$branding]);
        $stale = 0;

        foreach ($files as $abs) {
            $result['scanned']++;
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');

            // thumbs 是 JSON，键以字符串形式出现 —— 用带引号的模式匹配，并转义通配符
            $referenced->execute([$rel, $rel, '%"' . addcslashes($rel, '%_\\') . '"%']);
            if ($referenced->fetchColumn() !== false) {
                $result['skipped']++;
                continue;
            }

            $stale++;
            if ($stale > $batch) {
                $result['remaining']++;
                continue;
            }
            if (!$apply) {
                $result['planned']++;
                if (count($result['samples']) < 12) {
                    $result['samples'][] = ['path' => $rel, 'action' => 'would-delete'];
                }
                continue;
            }
            if (@unlink($abs)) {
                $result['deleted']++;
                if (count($result['samples']) < 12) {
                    $result['samples'][] = ['path' => $rel, 'action' => 'deleted'];
                }
                $this->pruneEmptyDirs(dirname($abs), $root);
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 列出目录下的文件（相对根的绝对路径），按路径排序保证可预期。
     *
     * - 只返回常规文件：符号链接与目录都跳过（不跟随链接，避免越界删除）；
     * - $excludeTop 里的顶层目录整体跳过（用于保护品牌 logo）；
     * - 最多返回 $limit 个（调用方按批处理，避免一次扫描巨量文件）。
     *
     * @param list<string> $excludeTop
     * @return list<string>
     */
    private function listFilesRecursive(string $dir, int $limit, array $excludeTop = []): array
    {
        $out = [];
        if (!is_dir($dir) || $limit <= 0) {
            return $out;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return $out;
        }
        sort($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $full = $dir . '/' . $entry;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if (in_array($entry, $excludeTop, true)) {
                    continue;
                }
                foreach ($this->listFilesRecursive($full, $limit - count($out)) as $f) {
                    $out[] = $f;
                    if (count($out) >= $limit) {
                        break;
                    }
                }
                continue;
            }
            if (is_file($full)) {
                $out[] = $full;
            }
        }
        return $out;
    }

    /** 自底向上删除空目录，绝不动 $stopAt 本身。 */
    private function pruneEmptyDirs(string $dir, string $stopAt): void
    {
        $stopAt = rtrim(str_replace('\\', '/', $stopAt), '/');
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        while ($dir !== '' && $dir !== $stopAt && str_starts_with($dir . '/', $stopAt . '/')) {
            if (!is_dir($dir) || (scandir($dir) ?: ['.', '..']) !== ['.', '..']) {
                return;
            }
            if (!@rmdir($dir)) {
                return;
            }
            $dir = rtrim(dirname($dir), '/');
        }
    }

    /**
     * v1.4.0-beta.2 增强: POST /admin/images/queue-clear —— 清空处理队列。
     *
     * scope：pending（仅待处理，默认）| failed（仅失败项）| all（两者）
     *
     * 语义是**删除数据库记录**（不是标记状态）。待处理行的原图只存在于服务器
     * 临时目录（storage/incoming），删除记录后一并清理；**失败行要特别注意**：
     * 原图在失败前**可能已经上传到最终存储**（上传失败只可能发生在缩略图阶段），
     * 删除记录会让那份文件成为存储侧的孤立对象，需操作员自行清理 —— 响应里以
     * orphan_risk 如实报告，前端确认文案也会提示。
     *
     * 补充（v1.5.0-beta.1）：这类**记录已删**的孤立对象无法被 cleanupStorage()
     * 发现（存储接口没有 LIST 能力），只能到对象存储控制台按前缀人工清理；
     * cleanupStorage() 负责的是记录仍在、但旧键/暂存/无引用文件等**可确定性判定**
     * 的残留。
     */
    public function queueClear(Request $request): void
    {
        $this->validateCsrf();
        $scope = (string) $request->input('scope', 'pending');
        if (!in_array($scope, ['pending', 'failed', 'all'], true)) {
            $this->json(['success' => false, 'error' => '无效的清理范围'], 400);
            return;
        }
        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $cond = match ($scope) {
            'pending' => "`process_status` = 'pending'",
            'failed'  => "`process_status` = 'failed'",
            default   => "`process_status` IN ('pending','failed')",
        };

        $rows = $pdo->query(
            "SELECT `id`, `path`, `process_status` FROM `images` WHERE {$cond}"
        )->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            $this->json(['success' => true, 'scope' => $scope, 'deleted' => 0, 'temp_removed' => 0, 'orphan_risk' => 0]);
            return;
        }

        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                "DELETE FROM `images` WHERE `id` IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
            );
            $st->execute($ids);
            $deleted = $st->rowCount();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->json(['success' => false, 'error' => '删除失败: ' . $e->getMessage()], 500);
            return;
        }

        // 清理临时原图（本地 incoming 目录）。失败忽略：文件可能已被前次运行清掉，
        // 或对象存储场景下本就不存在于本地。
        $incomingDir = self::incomingDir();
        $tempRemoved = 0;
        foreach ($rows as $r) {
            $file = $incomingDir . '/' . ltrim((string) $r['path'], '/');
            if (is_file($file)) {
                if (@unlink($file)) {
                    $tempRemoved++;
                }
                @rmdir(dirname($file));   // 顺手清空的日期目录
            }
        }

        $orphanRisk = 0;
        foreach ($rows as $r) {
            if ((string) $r['process_status'] === 'failed') {
                $orphanRisk++;   // 失败行的原图可能已进最终存储 → 删除记录会留下孤立文件
            }
        }

        $this->json([
            'success' => true,
            'scope' => $scope,
            'deleted' => $deleted,
            'temp_removed' => $tempRemoved,
            'orphan_risk' => $orphanRisk,
        ]);
    }

    /**
     * v1.3.2 迭代: 历史图片哈希回填 —— Web 分批端点（管理员，POST + CSRF）。
     *
     * 每次 POST 处理一小批（默认 5 张）缺哈希的图片：定位其存储实例、把字节
     * 取到服务器（对象存储经签名 URL 拉到临时文件，本地直接读）、对同一份
     * 字节算 MD5 + SHA-256、只补缺失字段、用完即删临时文件。前端循环调用并
     * 显示进度条。幂等 —— 已有双哈希的行永远跳过。
     */
    public function backfillHashes(Request $request): void
    {
        $this->validateCsrf();
        // v1.5.0-beta.2: 回填要逐行取对象字节再算哈希（云端每行一次网络往返），
        // 属于长任务 —— 致命错误同样要变成可读 JSON 而不是空响应。
        $this->jsonFatalGuard('backfill-hashes');

        $batchSize = max(1, min(20, (int) $request->input('batch', '5')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $missingCond = "(file_sha256 IS NULL OR file_sha256='' OR file_hash IS NULL OR file_hash='')";
        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE {$missingCond}"
        )->fetchColumn();

        $updated = 0;
        $failed = 0;
        $results = [];

        if ($remaining > 0) {
            $rows = $pdo->query(
                "SELECT * FROM `images` WHERE {$missingCond} ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                $hasMd5 = (string) ($row['file_hash'] ?? '') !== '';
                $hasSha = (string) ($row['file_sha256'] ?? '') !== '';

                try {
                    // 该图所属存储实例 —— storage_profile_id 精确优先，缺省回退默认实例
                    // v1.4.0-beta.2 性能: 走请求内缓存（原先每行查一次 → N+1）
                    $profile = $this->resolveProfile(
                        $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                    );
                    if ($profile === null) {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => '无可用存储实例'];
                        continue;
                    }
                    $driver = $profile->driver();

                    // —— 取字节到本地（对象存储拉临时文件；本地直读）——
                    $localFile = null;
                    $isTemp = false;
                    if ($driver instanceof \App\Storage\LocalDriver) {
                        $localFile = $driver->uploadDir() . '/' . ltrim($path, '/');
                        if (!is_file($localFile) || !is_readable($localFile)) {
                            $failed++;
                            $results[] = ['id' => $id, 'error' => '本地文件不可读'];
                            continue;
                        }
                    } else {
                        try {
                            $localFile = \App\Storage\S3Driver::downloadUrl($driver->url($path));
                        } catch (\Throwable $e) {
                            $localFile = null;
                        }
                        if ($localFile === null) {
                            $failed++;
                            $results[] = ['id' => $id, 'error' => '对象拉取失败'];
                            continue;
                        }
                        $isTemp = true;
                    }

                    // —— 同一份字节算双哈希 ——
                    $md5 = @hash_file('md5', $localFile);
                    $sha = @hash_file('sha256', $localFile);
                    if ($isTemp) {
                        @unlink($localFile);
                    }
                    if ($md5 === false || $sha === false || $md5 === '' || $sha === '') {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => '哈希计算失败'];
                        continue;
                    }

                    $image = new Image($row);
                    if (!$hasMd5) {
                        $image->file_hash = $md5;
                    }
                    if (!$hasSha) {
                        $image->file_sha256 = $sha;
                    }
                    if ($image->save()) {
                        $updated++;
                        $results[] = ['id' => $id, 'ok' => true];
                    } else {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => '保存失败'];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $results[] = ['id' => $id, 'error' => $e->getMessage()];
                }
            }

            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE {$missingCond}"
            )->fetchColumn();
        }

        $this->json([
            'success' => true,
            'total' => $total,
            'remaining' => $remaining,
            'updated' => $updated,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    public function upload(Request $request): void
    {
        $this->validateCsrf();
        // v1.5.0-beta.2: 上传路径同样做 GD 解码（原图转码 + 缩略图），必须把致命错误
        // 变成可读 JSON —— 否则一张大图就能让"上传"变成一个没有内容的 500，
        // 用户看不出是文件太大、内存不足，还是超时。
        $this->jsonFatalGuard('upload');

        $categoryId = $request->input('category_id', '');
        $categoryId = $categoryId !== '' ? (int) $categoryId : null;

        $files = $_FILES['images'] ?? null;
        if (!$files || empty($files['tmp_name'][0])) {
            Session::flash('error', 'No files uploaded.');
            $this->redirect('/admin/images');
        }

        // Resolve the storage instance for this upload. The operator can pick
        // any enabled profile in the upload dialog; otherwise the default
        // profile is used. v1.0.35: profiles are the single source of truth —
        // a missing usable profile is a hard error, no settings fallback.
        $profileId = (int) $request->input('storage_profile_id', '0');
        $profile = $profileId > 0 ? StorageProfile::find($profileId) : null;
        if ($profile === null || !$profile->isEnabled() || !$profile->isUsable()) {
            $profile = StorageProfile::defaultProfile();
        }
        if ($profile === null) {
            throw new \RuntimeException(
                '未配置任何启用的存储实例。请到后台「存储管理」新增存储实例并设为默认。'
            );
        }
        $storage = $profile->driver();
        $storageType = (string) $profile->driver;
        $storageProvider = (string) $profile->provider;
        $profileId = (int) $profile->id;
        $uploaded = 0;
        $errors = [];
        // v1.3.1 迭代: 内容级重复检测 —— MD5 内容哈希比对（批内 + 数据库）。
        // 字节在服务器手上，哈希由服务端计算，天然权威、不可被前端伪造。
        // 重复图片跳过不存储，汇总提示，不影响其余文件继续上传。
        $duplicates = [];
        $batchHashes = [];

        $fileCount = count($files['tmp_name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $tmpName = $files['tmp_name'][$i];
            $originalName = $files['name'][$i];
            $fileSize = $files['size'][$i];
            $mimeType = $files['type'][$i];

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "{$originalName}: Upload error code {$files['error'][$i]}";
                continue;
            }

            // v1.4.0-beta.2: 系统设置「单图大小上限」应用层校验（0 = 不限制，只受 PHP 约束）
            $maxBytes = self::uploadMaxBytes();
            if ($maxBytes > 0 && (int) $fileSize > $maxBytes) {
                $errors[] = sprintf(
                    '%s: 超过单图大小上限（%.1f MB > %d MB），请在「系统设置 → 图片与存储」调整',
                    $originalName,
                    (int) $fileSize / 1048576,
                    intdiv($maxBytes, 1048576)
                );
                continue;
            }

            // Validate MIME
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);

            if (!in_array($detectedMime, $this->allowedMimeTypes, true)) {
                $errors[] = "{$originalName}: Invalid file type ({$detectedMime})";
                continue;
            }

            // Validate extension
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowedExtensions, true)) {
                $errors[] = "{$originalName}: Invalid file extension (.{$ext})";
                continue;
            }

            // v1.5.0-beta.1: 原图统一转 WebP（可在「系统设置 → 图片与存储」关闭）。
            //
            // 位置很关键 —— 必须在**计算哈希之前**：记录的 file_hash/file_sha256 要
            // 描述**实际存进存储的字节**，否则与「哈希回填」重算出来的值不一致，去重
            // 也会跟着失效。转换失败/不划算时不阻断上传，保持原格式（见 convertOriginalToWebp）。
            $conv = $this->convertOriginalToWebp($tmpName, $detectedMime);
            if ($conv['ok']) {
                $ext = 'webp';
                $detectedMime = 'image/webp';
                $fileSize = (int) @filesize($tmpName);
            }

            // v1.3.2 迭代: 分层校验去重 —— MD5 快速初筛 + SHA-256 二次确认。
            // MD5 命中疑似重复后再用强哈希确定性判重，消除 MD5 碰撞伪重复。
            // 字节在服务器手上，哈希全部服务端计算，天然权威、不可被前端伪造。
            $md5 = @hash_file('md5', $tmpName);
            $sha256 = @hash_file('sha256', $tmpName);
            $fileHash = ($md5 !== false && $md5 !== '') ? $md5 : null;
            $fileSha256 = ($sha256 !== false && $sha256 !== '') ? $sha256 : null;

            if ($fileHash !== null && $fileSha256 !== null) {
                // ① 批内初筛（MD5，快）
                if (isset($batchHashes[$fileHash])) {
                    // 批内命中 —— 同批同文件必然同内容，直接判定重复。
                    // 但为严谨，仍用 SHA-256 复核（两个不同文件同 MD5 的
                    // 理论场景），确认一致才算重复。
                    $batch = $batchHashes[$fileHash];
                    if ($batch['sha256'] === $fileSha256) {
                        $duplicates[] = "{$originalName}：与本批次中「{$batch['name']}」重复，已跳过";
                        continue;
                    }
                    // 罕见：同 MD5 不同内容（碰撞）—— 不判重，按新图继续。
                }

                // ② 库内初筛（MD5，走索引）
                $existing = Image::firstWhere('file_hash', $fileHash);
                if ($existing !== null) {
                    // ③ 二次确认（SHA-256）：库内已有强哈希则直接比，零流量。
                    if ($existing->file_sha256 !== null && $existing->file_sha256 !== '') {
                        if ($existing->file_sha256 === $fileSha256) {
                            $duplicates[] = "{$originalName}：与已有图片「{$existing->original_name}」重复，已跳过";
                            continue;
                        }
                        // MD5 命中但 SHA-256 不符 → 互不重复，放行（防碰撞误杀）。
                    } else {
                        // 库内旧记录无强哈希 —— 从存储补算（仅疑似、存量少，低频）。
                        $archivedSha = $storage->hashFile($existing->path);
                        if ($archivedSha !== null && $archivedSha === $fileSha256) {
                            $duplicates[] = "{$originalName}：与已有图片「{$existing->original_name}」重复，已跳过";
                            continue;
                        }
                    }
                }

                // 记录批内（存 MD5 + SHA-256 供后续同批比对）
                $batchHashes[$fileHash] = ['name' => $originalName, 'sha256' => $fileSha256];
            }

            // v1.5.0-beta.1: 新上传走统一布局（方案 A，资产为中心）——
            //   {yyyy}/{mm}/{uuid}/original.{ext}，缩略图落在同目录 thumb-{size}.webp。
            // 旧布局对象不受影响（键推导按 path 形态自动选择规则）。
            $remotePath = \App\Models\Image::newAssetPath($ext);

            try {
                // Get image dimensions
                $imgInfo = @getimagesize($tmpName);
                $width = $imgInfo ? $imgInfo[0] : 0;
                $height = $imgInfo ? $imgInfo[1] : 0;

                // v1.3.2-beta.2 迭代: 异步处理管线 —— 上传落「临时目录」即为成功。
                // 记录以 process_status='pending' 入队，由管理页驱动的队列 Worker
                // 生成缩略图、上传最终存储并把记录置 done；临时文件随后删除。
                $incomingFile = self::incomingDir() . '/' . $remotePath;
                if (!is_dir(dirname($incomingFile))) {
                    @mkdir(dirname($incomingFile), 0755, true);
                }
                if (!@move_uploaded_file($tmpName, $incomingFile)) {
                    throw new \RuntimeException('临时文件写入失败（storage/incoming 不可写）');
                }

                $image = new Image([
                    // 新布局下存储文件名为 original.{ext}（旧布局曾是 {uuid}.{ext}）
                    'filename' => basename($remotePath),
                    'original_name' => $originalName,
                    'path' => $remotePath,
                    'url' => '',
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'file_hash' => $fileHash,
                    'file_sha256' => $fileSha256,
                    'width' => $width,
                    'height' => $height,
                    'category_id' => $categoryId,
                    'sort_order' => 0,
                    'status' => 'active',
                    'process_status' => 'pending',
                    'storage' => $storageType,
                    'storage_provider' => $storageProvider,
                    'storage_profile_id' => $profileId,
                ]);
                if (!$image->save()) {
                    // insert() can return false on a silent DB error (e.g. a
                    // missing `storage` column when the migration did not run).
                    // Surface it instead of leaving the file on disk with no
                    // database record, which reads as "uploaded but missing".
                    throw new \RuntimeException('数据库写入失败：图片记录未保存（请检查 images 表是否含 storage / storage_provider 列）');
                }
                $uploaded++;

            } catch (\Throwable $e) {
                $errors[] = "{$originalName}: Storage error - {$e->getMessage()}";
            }
        }

        // AJAX uploads (drag-drop with progress bar) get a JSON verdict instead
        // of the 302+flash dance. A blind XHR follow of a 302 consumes the
        // flash, so the operator would see nothing; returning JSON lets the
        // frontend show a toast with the real error (or success).
        $dupCount = count($duplicates);
        if ($this->isAjax()) {
            // v1.3.1: 全部为重复也算"有结果"（success=true）——不是服务器错误。
            if ($uploaded > 0 || $dupCount > 0) {
                $msg = $uploaded > 0 ? "上传成功 {$uploaded} 张（已进入处理队列，稍后自动完成存储与缩略图）" : '所选图片均为重复，未新增';
                if ($dupCount > 0) {
                    $msg .= $uploaded > 0 ? "，重复跳过 {$dupCount} 张" : "（共 {$dupCount} 张）";
                }
                $this->json([
                    'success' => true,
                    'message' => $msg,
                    'errors' => $errors,
                    'duplicates' => $duplicates,
                ]);
            }
            $this->json([
                'success' => false,
                'message' => '上传失败',
                'errors' => $errors,
            ], 500);
        }

        if ($uploaded > 0 || $dupCount > 0) {
            $msg = $uploaded > 0 ? "Successfully uploaded {$uploaded} image(s)." : '所选图片均为重复，未新增。';
            if ($dupCount > 0) {
                $msg .= $uploaded > 0 ? " 重复跳过 {$dupCount} 张。" : "（共 {$dupCount} 张）";
            }
            Session::flash('success', $msg);
        }
        if (!empty($errors)) {
            // v1.4.0-beta.2 修复（XSS）: flash 在 layout 里是**原样输出 HTML**（为支持
            // <br>），而错误信息里嵌了原始文件名 → 形如 `<img src=x onerror=…>.png`
            // 的文件名会被当作 HTML 执行（管理员会话内的注入）。这里在拼装 HTML 时
            // 逐条转义（implode 的 <br> 在转义之后加入，不受影响）；JSON 路径保持原文
            //（前端用 textContent 渲染，无需转义）。
            Session::flash('error', implode('<br>', array_map('h', $errors)));
        }

        $this->redirect('/admin/images');
    }

    public function update(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $image = Image::find($id);

        if (!$image) {
            $this->fail('图片不存在。', 404, self::PAGE);
        }

        $categoryId = $request->input('category_id');
        $image->category_id = $categoryId !== '' && $categoryId !== 'null' ? (int) $categoryId : null;
        $image->save();

        $this->ok('图片已更新。', ['id' => (int) $image->id], self::PAGE);
    }

    public function delete(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $image = Image::find($id);

        if (!$image) {
            $this->fail('图片不存在。', 404, self::PAGE);
        }

        if (!$image->delete()) {
            $this->fail('图片删除失败。', 500, self::PAGE);
        }

        $this->ok('已删除 1 张图片。', ['deleted' => [$id]], self::PAGE);
    }

    public function batchDelete(Request $request): void
    {
        $this->validateCsrf();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null || $ids === '' ? [] : [$ids];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            $this->fail('未选择任何图片。', 400, self::PAGE);
        }

        $deleted = [];
        $failed = [];
        foreach ($ids as $id) {
            $image = Image::find($id);
            if (!$image) {
                $failed[] = $id;
                continue;
            }
            if ($image->delete()) {
                $deleted[] = $id;
            } else {
                $failed[] = $id;
            }
        }

        if (empty($deleted)) {
            $this->fail('删除失败，未能移除任何图片。', 500, self::PAGE);
        }

        $message = '已删除 ' . count($deleted) . ' 张图片。';
        if (!empty($failed)) {
            $message .= ' ' . count($failed) . ' 张删除失败。';
        }

        $this->ok($message, ['deleted' => $deleted, 'failed' => $failed], self::PAGE);
    }

    /**
     * v1.2.1 迭代: bulk re-categorize selected images (admin UI audit I2).
     * POST /admin/images/batch-categorize { ids: [], category_id: N|0 }
     * category_id 0 clears the category.
     */
    public function batchCategorize(Request $request): void
    {
        $this->validateCsrf();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null || $ids === '' ? [] : [$ids];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            $this->fail('未选择任何图片。', 400, self::PAGE);
        }
        $categoryId = (int) $request->input('category_id', '0');
        if ($categoryId < 0) {
            $categoryId = 0;
        }
        if ($categoryId > 0 && !\App\Models\Category::find($categoryId)) {
            $this->fail('分类不存在。', 400, self::PAGE);
        }

        $ok = 0;
        foreach ($ids as $id) {
            $image = Image::find($id);
            if (!$image) {
                continue;
            }
            if ($image->update(['category_id' => $categoryId === 0 ? null : $categoryId])) {
                $ok++;
            }
        }
        if ($ok === 0) {
            $this->fail('未能更新任何图片分类。', 500, self::PAGE);
        }
        \App\Models\AuditLog::record('image_batch_category', [
            'count' => $ok,
            'category_id' => $categoryId,
        ]);
        $message = '已更新 ' . $ok . ' 张图片的'
            . ($categoryId === 0 ? '分类（未分类）。' : '分类。');
        $this->ok($message, ['updated' => $ok, 'category_id' => $categoryId], self::PAGE);
    }

    public function sort(Request $request): void
    {
        $this->validateCsrf();
        $order = $request->input('order', []);
        if (empty($order) || !is_array($order)) {
            $this->json(['error' => 'No order data.'], 400);
        }

        $data = [];
        foreach ($order as $index => $id) {
            $data[] = ['id' => (int) $id, 'sort_order' => $index];
        }

        Image::updateSortOrders($data);
        $this->json(['success' => true, 'message' => 'Sort order updated.']);
    }
}
