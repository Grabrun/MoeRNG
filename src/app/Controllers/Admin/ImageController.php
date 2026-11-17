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
use App\Models\ContentHash;
use App\Models\StorageProfile;

class ImageController extends Controller
{
    private const PAGE = '/admin/images';

    /** 重型端点内存上限；OOM 不可捕获，真正的防线是 decodeWouldExceedMemory() 的预检。 */
    private const HEAVY_MEMORY_LIMIT = '512M';

    /** GD 解码系数：位图为 w×h×4 字节，libwebp/重采样还需额外缓冲。 */
    private const DECODE_MEMORY_FACTOR = 1.25;

    /** 解码额外余量（目标画布 + 编码缓冲 + 基线开销）。 */
    private const DECODE_MEMORY_HEADROOM = 33554432; // 32 MiB

    /** 本请求处理中的行 id：致命错误时由 shutdown 钩子标记为 failed，否则会永久卡在 processing。 */
    private static array $inflightIds = [];

    private array $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/bmp',
        // 不收 SVG：文档式加载的 SVG 可执行脚本，CSP 拦不住，等于存储型 XSS。
    ];

    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function index(Request $request): void
    {
        $page = max(1, (int) $request->input('page', '1'));
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

        $sql = 'SELECT id FROM images';
        if ($where !== '1=1') {
            $sql .= " WHERE {$where}";
        }
        $sql .= ' ORDER BY id DESC';
        $rows = Image::query($sql, $params);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $this->json(['ids' => $ids, 'total' => count($ids)]);
    }

    private static function thumbsEnabled(): bool
    {
        return (string) Config::get('settings.thumbs_enabled', '1') !== '0';
    }

    private static function thumbQuality(): int
    {
        $q = (int) Config::get('settings.thumb_quality', '82');
        return max(40, min(100, $q > 0 ? $q : 82));
    }

    /** 缩略图像素上限；0 = 不设上限，仅按可用内存判断。 */
    private static function thumbMaxPixels(): int
    {
        $wan = (int) Config::get('settings.thumb_max_pixels', '0');   // 单位：万像素
        return $wan > 0 ? $wan * 10000 : 0;
    }

    /** 单图大小上限；0 = 不限制，只受 PHP 配置约束。 */
    private static function uploadMaxBytes(): int
    {
        $mb = (int) Config::get('settings.upload_max_mb', '0');
        return $mb > 0 ? $mb * 1048576 : 0;
    }

    private static function originalWebpEnabled(): bool
    {
        return (string) Config::get('settings.original_webp_enabled', '1') !== '0';
    }

    /** 编码临时后缀；同目录 rename 即原子替换，成功前不动源文件。 */
    private const WEBP_TEMP_SUFFIX = '.wip-webp';

    private const CONVERT_MEMORY_HEADROOM = 16777216; // 16 MiB
    private const MEMORY_BASELINE_HEADROOM = 16777216;

    /** 单批上限：set_time_limit(120) 下 50 张约 15–75 s，再大容易撞超时，超时则整批重来。 */
    public const QUEUE_BATCH_MAX = 50;

    public const QUEUE_BATCH_DEFAULT = 20;

    /** 单请求墙钟预算，必须明显低于网关/FPM 超时；被 FPM 强杀时 shutdown 钩子来不及跑，本批会卡满 5 分钟复位窗口。 */
    private const QUEUE_TIME_BUDGET = 45;

    /** 解码前的水位快照（只取一次）：memory_get_usage(true) 在请求内单调不减，逐图现取会让靠后的图被误判 too-large。 */
    private ?int $memoryBaseline = null;

    private function memoryBaseline(): int
    {
        return $this->memoryBaseline ??= memory_get_usage(true);
    }

    /** 估算值须 ≤ 可用内存 × 该系数（吸收编码器同级缓冲的误差）。 */
    private const MEMORY_SAFETY_FACTOR = 0.85;

    private static function originalWebpQuality(): int
    {
        $q = (int) Config::get('settings.original_webp_quality', '90');
        return max(40, min(100, $q > 0 ? $q : 90));
    }

    /**
     * 转 WebP 到 $destFile（缺省原地）：先写同目录临时文件，判定更小才 rename，失败时源文件不动。
     *
     * @return array{ok: bool, note: string, bytes?: int, detail?: string}
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

        // 转码不能沿用缩略图的解码预算：imagewebp 编码期还要一块同级画布，按解码量估算会一路走到编码器才 OOM。
        $budget = $this->originalConvertBudget($file, $mime, true);
        if ($budget !== null) {
            return ['ok' => false, 'note' => 'too-large', 'detail' => $budget['detail']];
        }

        // 0 = JPEG 读不到 EXIF（宁可不转）；1 = 正常；2~8 = 需纠正
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

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($src);
        }
        @imagealphablending($src, false);
        @imagesavealpha($src, true);

        $dest = $destFile ?? $file;
        $tmpOut = $dest . self::WEBP_TEMP_SUFFIX;

        // 直接落盘：不把整幅编码结果抓进 PHP 变量（原图可达数 MiB）
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
            // 转完更大就保留原格式；源文件未动
            @unlink($tmpOut);
            return ['ok' => false, 'note' => 'not-smaller'];
        }

        // 同目录 rename = 原子替换；失败则源文件依旧完好
        if (!@rename($tmpOut, $dest)) {
            @unlink($tmpOut);
            return ['ok' => false, 'note' => 'write-failed'];
        }

        return ['ok' => true, 'note' => 'done', 'bytes' => $outSize];
    }

    /** @return int 0 = exif 扩展不可用（放弃转换）；1 = 无需纠正；2~8 = EXIF 方向值。 */
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
     * 原图转码的内存预算：峰值按解码/编码/EXIF 旋转三块画布取最大。
     *
     * @param bool $applyPixelCap 只有上传路径传 true，批量转换不套像素上限
     * @return array{code: string, detail: string}|null null = 预算内
     */
    private function originalConvertBudget(string $file, string $mime, bool $applyPixelCap = false): ?array
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

        // 运营侧像素上限（万像素，0 = 不限），只在上传路径生效
        if ($applyPixelCap) {
            $cap = self::thumbMaxPixels();
            if ($cap > 0 && $w * $h > $cap) {
                return [
                    'code'   => 'pixel-cap',
                    'detail' => sprintf(
                        '图像超出设置上限（%d×%d = %.1f 万像素，上限 %.0f 万像素）',
                        $w,
                        $h,
                        $w * $h / 10000,
                        $cap / 10000
                    ),
                ];
            }
        }

        $canvas = (float) $w * $h * 4;                 // 一块真彩画布（GD 真彩 = 4 B/px）
        $bytes  = (int) @filesize($file);              // 整个文件字节同时也在内存里
        $rotate = self::jpegOrientation($file, $mime) >= 2;

        // 三个阶段峰值不同时出现，取最大
        $decode = $bytes + $canvas;
        $encode = $canvas * 2;                         // 编码器还要一块与画布同级的缓冲
        $rot    = $rotate ? $canvas * 2 : 0;           // imagerotate 期间新旧两块画布并存
        $need   = (int) (max($decode, $encode, $rot) + $canvas * 0.25)
                + self::CONVERT_MEMORY_HEADROOM;

        $limit = self::memoryLimitBytes();
        if ($limit === PHP_INT_MAX) {
            return null;   // 无内存限制 → 交给解码器
        }
        $available = $limit - $this->memoryBaseline() - self::MEMORY_BASELINE_HEADROOM;
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

    /** 浏览器对 JPEG 应用 EXIF 方向、对 WebP 不会 —— 转码前必须自己摆正，否则手机竖拍照片会转 90°。 */
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
     * 解码前像素预检：GD 展开整幅位图要 w×h×4 字节，放不下就跳过，否则 OOM 不可捕获。
     *
     * @return ?string null = 可以解码
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
        $available = $limit - $this->memoryBaseline() - self::MEMORY_BASELINE_HEADROOM;
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

    /**
     * 一次读盘算两个摘要：SHA-256 是判重键，MD5 只用于匹配尚未回填索引的老数据。
     *
     * @return array{sha256:?string, md5:?string}
     */
    private function sha256File(string $file): ?string
    {
        $hash = hash_init('sha256');
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        while (!feof($fh)) {
            $chunk = fread($fh, 1048576);
            if ($chunk === false) {
                break;
            }
            hash_update($hash, $chunk);
        }
        fclose($fh);
        $digest = hash_final($hash);
        return $digest === '' ? null : $digest;
    }

    /**
     * 查索引 → 未命中则原子占位。返回 null = 抢到（可以继续处理）；否则返回占用者。
     * 占位失败只有两种可能：并发方刚插入，或失败的旧行还占着（已就地清掉）。
     *
     * @return array{sha256:string, image_id:?int, kind:string}|null
     */
    private function claimHash(string $sha256, string $kind): ?array
    {
        for ($pass = 0; $pass < 3; $pass++) {
            $hit = ContentHash::find($sha256);
            if ($hit === null) {
                if (ContentHash::reserve($sha256, $kind)) {
                    return null;
                }
                continue;
            }
            if ($this->purgeFailedHolder($hit)) {
                continue;
            }
            return $hit;
        }
        return ContentHash::find($sha256) ?? ['sha256' => $sha256, 'image_id' => null, 'kind' => $kind];
    }

    /** 处理失败的图不该永久占住哈希：删掉它（外键级联释放索引），让重传把这张图修好。 */
    private function purgeFailedHolder(array $hit): bool
    {
        $id = $hit['image_id'] ?? null;
        if ($id === null) {
            return false;
        }
        $img = Image::find((int) $id);
        if ($img === null) {
            ContentHash::release((string) $hit['sha256']);
            return true;
        }
        if ((string) ($img->process_status ?? '') !== 'failed') {
            return false;
        }
        try {
            return $img->delete();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{sha256:string, image_id:?int, kind:string} $hit */
    private function duplicateNote(string $name, array $hit): string
    {
        $id = $hit['image_id'] ?? null;
        if ($id === null) {
            return "{$name}：相同内容正在上传或处理中，已跳过";
        }
        $img = Image::find((int) $id);
        if ($img === null) {
            return "{$name}：与库中已有内容重复，已跳过";
        }
        if ((string) ($img->process_status ?? '') !== 'done') {
            return "{$name}：与库中正在处理的图片「{$img->original_name}」相同，已跳过";
        }
        $kind = (string) ($hit['kind'] ?? '');
        if (str_starts_with($kind, 'thumb_')) {
            return "{$name}：与图片「{$img->original_name}」的缩略图（" . substr($kind, 6) . '）内容相同，已跳过';
        }
        return "{$name}：与已有图片「{$img->original_name}」重复，已跳过";
    }

    /**
     * 索引未覆盖的历史行兜底：按 file_sha256 列再查一次，命中即把哈希补写进索引。
     * 只比 SHA-256 —— MD5 已从数据模型退役，无法也不该与强哈希混比。
     */
    private function legacyDuplicateBySha(string $srcSha256, string $dstSha256): ?Image
    {
        $hit = Image::findBySha256(array_values(array_unique(array_filter([$srcSha256, $dstSha256]))));
        if ($hit === null) {
            return null;
        }
        $known = trim((string) $hit->file_sha256);
        if ($known !== '') {
            try {
                $kind = ($known === $dstSha256 && $dstSha256 !== $srcSha256)
                    ? ContentHash::KIND_STORED
                    : ContentHash::KIND_SOURCE;
                ContentHash::record((int) $hit->id, [$kind => $known]);
            } catch (\Throwable) {
            }
        }
        return $hit;
    }

    /**
     * 多尺寸缩略图（不放大，单档失败不影响其它档）。
     *
     * @return array{readable: bool, thumbs: array<string, array{tmp: string, key: string}>, reason: string}
     */
    private function makeThumbnails(string $srcFile, string $mime, string $path): array
    {
        // 总开关关闭时不生成缩略图，thumbs 留空以便重新开启后仍可被「补全缩略图」收录
        if (!self::thumbsEnabled()) {
            return ['readable' => false, 'thumbs' => [], 'reason' => 'disabled'];
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return ['readable' => false, 'thumbs' => [], 'reason' => 'gd-unavailable'];
        }

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
        $quality = self::thumbQuality();
        $out = [];
        // 已落盘但尚未并入 $out 的临时文件：并入前抛异常必须自己删（见 catch）
        $pendingTmp = null;

        try {
            foreach (\App\Models\Image::THUMB_SIZES as $size => $target) {
                // 与 API 读取端共用同一份尺寸推导，报出的宽高必然等于实际生成的图
                $dims = \App\Models\Image::thumbDimensions($w, $h, $target);
                if ($dims === null) {
                    continue;   // 不放大
                }
                [$tw, $th] = $dims;

                $dst = imagecreatetruecolor($tw, $th);
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
                    continue;
                }
                $pendingTmp = $tmp;
                // 字节数生成时实测（取决于质量设置与 libwebp 版本，事后无法推导）
                $bytes = (int) @filesize($tmp);
                // thumbKey() 对历史布局会抛 RuntimeException，故必须在并入 $out 之前调用以便自清理
                $out[$size] = [
                    'tmp' => $tmp,
                    'key' => \App\Models\Image::thumbKey($size, $path),
                    'bytes' => $bytes > 0 ? $bytes : null,   // 0 = 未知
                    // 产出哈希进索引：用户把本站缩略图存下来再传时能被认出
                    'sha256' => @hash_file('sha256', $tmp) ?: null,
                ];
                $pendingTmp = null;
            }
        } catch (\Throwable $e) {
            // 本函数是临时文件的唯一产生者：异常时调用方拿不到返回数组，只能在这里删
            if ($pendingTmp !== null) {
                @unlink($pendingTmp);
            }
            foreach ($out as $t) {
                if (!empty($t['tmp'])) @unlink($t['tmp']);
            }
            throw $e;
        } finally {
            imagedestroy($src);
        }

        return ['readable' => true, 'thumbs' => $out, 'reason' => 'ok'];
    }

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
            $info = function_exists('getimagesize') ? @getimagesize($file) : false;
            if (is_array($info) && !empty($info['mime'])) {
                $src = $open((string) $info['mime']);
            }
        }
        return ($src === null || $src === false) ? null : $src;
    }

    /** 兜底清理未上传的临时缩略图（uploadThumbs 内部已 finally，这里覆盖其之前抛异常的路径）。 */
    private function discardThumbs(?array $gen): void
    {
        if (!is_array($gen) || empty($gen['thumbs'])) return;
        foreach ($gen['thumbs'] as $t) {
            if (!empty($t['tmp'])) @unlink($t['tmp']);
        }
    }

    /** 把成功上传的缩略图哈希写进索引；失败不致命，图片本身已经入库。 */
    private function recordThumbHashes(int $imageId, array $hashes): void
    {
        $pairs = [];
        foreach ($hashes as $size => $hash) {
            $kind = ContentHash::THUMB_KINDS[(string) $size] ?? null;
            if ($kind !== null && is_string($hash) && $hash !== '') {
                $pairs[$kind] = $hash;
            }
        }
        if ($pairs === []) {
            return;
        }
        try {
            ContentHash::record($imageId, $pairs);
        } catch (\Throwable) {
        }
    }

    private function uploadThumbs(\App\Storage\StorageInterface $driver, array $thumbs): array
    {
        $keys = [];
        $bytes = [];
        $hashes = [];
        foreach ($thumbs as $size => $t) {
            try {
                $driver->upload($t['tmp'], $t['key'], 'image/webp');
                $keys[$size] = $t['key'];
                // 只记**上传成功**档的字节数与哈希（失败档的对象不存在，记了就是谎报）
                $b = (int) ($t['bytes'] ?? 0);
                if ($b > 0) {
                    $bytes[$size] = $b;
                }
                if (is_string($t['sha256'] ?? null) && $t['sha256'] !== '') {
                    $hashes[$size] = $t['sha256'];
                }
            } catch (\Throwable) {
                // 单档失败不影响其它档
            } finally {
                @unlink($t['tmp']);
            }
        }
        return ['keys' => $keys, 'bytes' => $bytes, 'hashes' => $hashes];
    }

    /** 致命错误也让端点返回可读 JSON（否则响应体为空，前端只看到 "Unexpected end of JSON input"）。 */
    private function jsonFatalGuard(string $label): void
    {
        @ini_set('memory_limit', self::HEAVY_MEMORY_LIMIT);
        @set_time_limit(120);

        register_shutdown_function(static function () use ($label): void {
            $e = error_get_last();
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!$e || !in_array($e['type'], $fatalTypes, true)) {
                return;
            }
            $detail = $e['message'] . ' @ ' . basename((string) $e['file']) . ':' . $e['line'];

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
                    // 忽略：下次运行开头仍会复位 processing 行
                }
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
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

    /** 临时存放目录（storage/incoming，web 不可达）。 */
    public static function incomingDir(): string
    {
        return dirname(__DIR__, 3) . '/storage/incoming';
    }

    /** 每次处理一小批 pending：生成缩略图 → 上传 → 置 done；失败置 failed 并留临时文件供重试。 */
    public function processQueue(Request $request): void
    {
        $this->jsonFatalGuard('process-queue');

        // 回收中途死掉的请求留下的空占位（10 分钟内不动才算，避免误删正在处理的）
        try {
            ContentHash::sweepStale(10);
        } catch (\Throwable) {
        }

        $batchSize = max(1, min(self::QUEUE_BATCH_MAX, (int) $request->input('batch', (string) self::QUEUE_BATCH_DEFAULT)));
        // 预算从认领前开始算：复位/探测/取批同样受网关超时约束
        $startedAt = microtime(true);

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        // 复位上次中断遗留的 processing 行；陈旧窗口避免把其它 worker 在途的行打回 pending
        try {
            $pdo->exec(
                "UPDATE `images` SET `process_status` = 'pending' "
                . "WHERE `process_status` = 'processing' "
                . "AND `updated_at` < (NOW() - INTERVAL 5 MINUTE)"
            );
        } catch (\Throwable) {
            // 缺 updated_at 列等异常忽略：宁可残留，不误伤在途任务
        }

        $hasPending = $pdo->query(
            "SELECT 1 FROM `images` WHERE process_status = 'pending' LIMIT 1"
        )->fetchColumn() !== false;

        $done = 0;
        $failed = 0;
        $skipped = 0;   // 原图过大而跳过缩略图的数量（原图仍正常入库）
        $results = [];

        if ($hasPending) {
            $rows = $pdo->query(
                "SELECT `id`, `path`, `storage_profile_id`, `mime_type` FROM `images` "
                . "WHERE process_status = 'pending' ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $incomingDir = self::incomingDir();

            if ($rows !== []) {
                $ids = array_map(static fn($r) => (int) $r['id'], $rows);
                // 条件更新：并发请求不会重复拾取同一批
                $marked = $pdo->exec(
                    "UPDATE `images` SET `process_status` = 'processing' "
                    . "WHERE `id` IN (" . implode(',', $ids) . ") AND `process_status` = 'pending'"
                );
                if ($marked !== count($ids)) {
                    // 已被并发方抢先：返回 contended 让前端退避，否则会退化成以 RTT 为间隔的紧循环
                    $this->json([
                        'success' => true,
                        'contended' => true,
                        'done' => 0,
                        'failed' => 0,
                        'skipped' => 0,
                        'remaining' => (int) $pdo->query(
                            "SELECT COUNT(*) FROM `images` WHERE `process_status` = 'pending'"
                        )->fetchColumn(),
                        'results' => [],
                    ]);
                    return;
                }
                self::$inflightIds = $ids;
            }

            foreach ($rows as $row) {
                $id = (int) $row['id'];

                // 到点先做完当前张，再把没碰过的行还回 pending——否则它们会卡满 5 分钟复位窗口
                if ($results !== [] && (microtime(true) - $startedAt) >= self::QUEUE_TIME_BUDGET) {
                    $processed = array_map(static fn($r) => (int) $r['id'], $results);
                    $rest = array_values(array_diff($ids, $processed));
                    if ($rest !== []) {
                        try {
                            $pdo->exec(
                                "UPDATE `images` SET `process_status` = 'pending'"
                                . " WHERE `process_status` = 'processing' AND `id` IN (" . implode(',', $rest) . ")"
                            );
                        } catch (\Throwable) {
                            // 还原失败也不致命：会走 5 分钟复位
                        }
                    }
                    break;
                }

                $path = (string) $row['path'];
                $incomingFile = $incomingDir . '/' . ltrim($path, '/');

                // 声明在 try 外：catch 用它们清理临时缩略图、回滚已上传对象（异常可能发生在赋值前）
                $gen = null;
                $uploadedKeys = [];
                $storage = null;

                try {
                    if (!is_file($incomingFile)) {
                        throw new \RuntimeException('临时文件不存在（可能已被清理）');
                    }

                    $profile = StorageProfile::resolve(
                        $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                    );
                    if ($profile === null) {
                        throw new \RuntimeException('无可用存储实例');
                    }
                    $storage = \App\Models\StorageProfile::cachedDriver($profile);

                    $mime = (string) ($row['mime_type'] ?? '');

                    $url = $storage->upload($incomingFile, $path, $mime);
                    $uploadedKeys[] = $path;

                    $gen = $this->makeThumbnails($incomingFile, $mime, $path);
                    $genReason = (string) ($gen['reason'] ?? '');
                    if ($genReason === 'too-large') {
                        // 原图过大已跳过缩略图；仍按 done 收尾，否则会反复重试卡住队列
                        $skipped++;
                    }

                    $thumbUp = $this->uploadThumbs($storage, $gen['thumbs']);
                    $this->recordThumbHashes($id, $thumbUp['hashes'] ?? []);
                    $thumbKeys = $thumbUp['keys'];
                    $thumbPath = $thumbKeys['md'] ?? null;
                    foreach ($thumbKeys as $thumbKey) {
                        if (is_string($thumbKey) && $thumbKey !== '') {
                            $uploadedKeys[] = $thumbKey;
                        }
                    }

                    // 总开关关闭时故意留空，使这些行仍留在「缺少缩略图」集合里供补全工具收走
                    $thumbsValue = $genReason === 'disabled' ? '' : \App\Models\Image::encodeThumbs($thumbKeys);
                    $thumbBytesValue = $genReason === 'disabled' ? '' : \App\Models\Image::encodeThumbBytes($thumbUp['bytes']);
                    // 状态推导与补全工具共用 Image::deriveThumbMeta，避免两处口径不一致
                    // WHERE 带 processing 守卫：行已被并发方写走时 rowCount=0，不覆盖对方状态
                    $this->writeThumbMetaState(
                        $pdo,
                        $id,
                        null,
                        \App\Models\Image::deriveThumbMeta($thumbsValue, (string) $thumbPath, $thumbBytesValue),
                        ['`url` = ?', '`thumb_path` = ?', '`thumbs` = ?', '`thumb_bytes` = ?', "`process_status` = 'done'", '`process_error` = NULL'],
                        [$url, $thumbPath, $thumbsValue, $thumbBytesValue],
                        "AND `process_status` = 'processing'"
                    );

                    @unlink($incomingFile);
                    @rmdir(dirname($incomingFile));

                    $done++;
                    $entry = ['id' => $id, 'ok' => true];
                    if ($genReason === 'too-large') {
                        $entry['note'] = '原图过大，已跳过缩略图（原图正常入库）';
                    }
                    $results[] = $entry;
                } catch (\Throwable $e) {
                    $this->discardThumbs($gen);
                    $failed++;
                    $err = mb_substr($e->getMessage(), 0, 480);
                    try {
                        // 同样带 processing 守卫，不把并发方已写好的 done 改判 failed
                        $pdo->prepare("UPDATE `images` SET `process_status` = 'failed', `process_error` = ? WHERE `id` = ? AND `process_status` = 'processing'")
                            ->execute([$err, $id]);
                    } catch (\Throwable) { /* 忽略 */ }
                    // 回滚已落桶的对象：这些 key 没有任何 DB 记录可指认，不删就是云端孤儿
                    if ($storage !== null && $uploadedKeys !== []) {
                        $leftKeys = [];
                        foreach ($uploadedKeys as $uk) {
                            try {
                                if (!$storage->delete($uk)) {
                                    $leftKeys[] = $uk;
                                }
                            } catch (\Throwable) {
                                $leftKeys[] = $uk;
                            }
                        }
                        if ($leftKeys !== []) {
                            // 残留清单写审计 detail.paths（process_error 只有 480 字符，装不下）
                            try {
                                \App\Models\AuditLog::record('image_rollback_failed', [
                                    'id'    => $id,
                                    'paths' => array_values(array_unique($leftKeys)),
                                    'stage' => 'queue_process_rollback',
                                ]);
                            } catch (\Throwable) { /* 审计失败不再抛 */ }
                        }
                    }
                    $results[] = ['id' => $id, 'error' => $err];
                }
            }

        }

        // 统计与 /queue-stats 共用同一份实现
        $stats = $this->queueStatsSnapshot($pdo);

        // remaining 只计 pending：failed 需人工重试，并入会让前端循环永不终止
        $remaining = $stats['pending'];
        $failedLeft = $stats['failed'];

        $this->json([
            'success' => true,
            'total' => $stats['total'],
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
     * 队列统计的唯一来源：GROUP BY 状态计数 + SUM(CASE) 派生计数。
     * @return array{pending:int, processing:int, done:int, failed:int, no_thumb:int, meta_retryable:int, total:int}
     */
    private function queueStatsSnapshot(\PDO $pdo): array
    {
        $byStatus = [];
        $total = 0;
        foreach ($pdo->query(
            "SELECT `process_status` AS s, COUNT(*) AS n FROM `images` GROUP BY `process_status`"
        ) as $r) {
            $byStatus[(string) $r['s']] = (int) $r['n'];
            $total += (int) $r['n'];
        }

        $derived = $pdo->query(
            "SELECT "
            . "COALESCE(SUM(CASE WHEN `process_status` = 'done' AND (" . \App\Models\Image::thumbMetaPendingSql() . ") THEN 1 ELSE 0 END), 0) AS no_thumb, "
            . "COALESCE(SUM(CASE WHEN `process_status` = 'done' AND (" . \App\Models\Image::thumbMetaRetryableSql() . ") THEN 1 ELSE 0 END), 0) AS meta_retryable "
            . "FROM `images`"
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'pending'        => $byStatus['pending'] ?? 0,
            'processing'     => $byStatus['processing'] ?? 0,
            'done'           => $byStatus['done'] ?? 0,
            'failed'         => $byStatus['failed'] ?? 0,
            'no_thumb'       => (int) ($derived['no_thumb'] ?? 0),
            'meta_retryable' => (int) ($derived['meta_retryable'] ?? 0),
            'total'          => $total,
        ];
    }

    /**
     * POST /admin/images/backfill-thumbs —— 补全存量缩略图（分批、幂等）。
     * 不改 process_status：置回 pending 会让存量图因前台只取 done 而集体下架。
     */
    public function backfillThumbs(Request $request): void
    {
        $this->jsonFatalGuard('backfill-thumbs');
        $batchSize = max(1, min(self::QUEUE_BATCH_MAX, (int) $request->input('batch', (string) self::QUEUE_BATCH_DEFAULT)));

        // pending = 生成缩略图/补字节数；retry = 定向重试 partial/failed 行
        $scope = (string) $request->input('scope', 'pending');
        if (!in_array($scope, ['pending', 'retry'], true)) {
            $this->json(['success' => false, 'error' => '无效的补全范围'], 400);
            return;
        }
        $isRetry = $scope === 'retry';

        // retry 只问对象长度，不解码不生成，故不受总开关与 GD 检查约束
        if (!$isRetry) {
            if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
                $this->json(['success' => false, 'error' => 'PHP GD 或 WebP 支持不可用，无法生成缩略图（请安装/启用 gd 扩展的 webp 支持）'], 500);
                return;
            }

            // 总开关关闭时拒绝补全，否则会把行全标成已处理，重开后还需人工重置
            if (!self::thumbsEnabled()) {
                $this->json(['success' => false, 'error' => '缩略图生成已在「系统设置 → 图片与存储」中关闭，请先开启后再补全'], 400);
                return;
            }
        }

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $targetCond = $isRetry
            ? \App\Models\Image::thumbMetaRetryableSql()
            : \App\Models\Image::thumbMetaPendingSql();

        // retry 必须用扫描游标：失败行重试后状态仍是 failed，按判据重选会每轮取到同一批，remaining 永不归零
        $fromId  = $isRetry ? max(0, (int) $request->input('from', '0')) : 0;
        $fromSql = $isRetry ? ' AND `id` > ' . (int) $fromId : '';

        // 排他锁避免管理页按钮与 CLI 子进程同时跑同一批（双上传，同 key 覆盖但双倍 egress）
        // 拿不到锁立即返回；锁文件打不开时降级为无锁直跑，不把环境问题放大成功能不可用
        $backfillLock = @fopen(dirname(__DIR__, 3) . '/storage/backfill-thumbs.lock', 'c');
        if (is_resource($backfillLock) && !flock($backfillLock, LOCK_EX | LOCK_NB)) {
            @fclose($backfillLock);
            $waitingRemaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$targetCond}{$fromSql}"
            )->fetchColumn();
            $this->json([
                'success' => true,
                'scope' => $scope,
                'total' => $waitingRemaining,
                'remaining' => $waitingRemaining,
                'scanned' => 0,
                'next_from' => $fromId,
                'done' => 0,
                'failed' => 0,
                'results' => [],
                'skipped' => 'already_running',
            ]);
            return;
        }

        $total = $isRetry
            ? (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$targetCond}")->fetchColumn()
            : (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$targetCond}{$fromSql}"
        )->fetchColumn();

        $done = 0;
        $failed = 0;
        $scanned = 0;
        $lastId = $fromId;
        $results = [];

        if ($remaining > 0) {
            $rows = $pdo->query(
                // 显式列：done 行的 url 在有 CDN 时可达 KB 级，SELECT * 浪费最实在
                "SELECT `id`, `path`, `storage_profile_id`, `mime_type`, `thumbs`, `thumb_path`, `thumb_bytes`, `processing_state` "
                . "FROM `images` WHERE process_status = 'done' AND {$targetCond}{$fromSql} ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                $scanned++;
                $lastId = max($lastId, $id);
                $gen = null;
                // 必须在 try 外声明：异常展开后本帧引用丢失，finally 就删不掉这个副本
                $localFile = null;
                $isTemp = false;
                try {
                    // 零网络快速路径：thumbs/thumb_bytes 已完整，只是状态还没写下来（也顺手自愈历史不一致）
                    $derived = \App\Models\Image::deriveThumbMeta(
                        $row['thumbs'] !== null ? (string) $row['thumbs'] : null,
                        (string) $row['thumb_path'],
                        $row['thumb_bytes'] !== null ? (string) $row['thumb_bytes'] : null
                    );
                    if ($derived === \App\Models\Image::STATE_OK) {
                        $this->writeThumbMetaState($pdo, $id, $row['processing_state'] ?? null, $derived);
                        $done++;
                        $results[] = ['id' => $id, 'ok' => true, 'note' => '元数据已完整，仅登记状态'];
                        continue;
                    }

                    $profile = StorageProfile::resolve(
                        $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                    );
                    if ($profile === null) {
                        throw new \RuntimeException('无可用存储实例');
                    }
                    $driver = \App\Models\StorageProfile::cachedDriver($profile);

                    // 只实测对象长度，不重编码：重编码的数字未必等于已存对象大小，又成了谎报
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
                        $bytesJson = \App\Models\Image::encodeThumbBytes($bytes);
                        $state = \App\Models\Image::deriveThumbMeta(
                            (string) $row['thumbs'],
                            (string) $row['thumb_path'],
                            $bytesJson
                        );
                        $this->writeThumbMetaState(
                            $pdo,
                            $id,
                            $row['processing_state'] ?? null,
                            $state,
                            ['`thumb_bytes` = ?'],
                            [$bytesJson]
                        );
                        $done++;
                        $note = '已实测缩略图字节数（' . count($bytes) . '/' . count($haveThumbs) . ' 档）';
                        if ($state === \App\Models\Image::STATE_FAILED) {
                            $note .= '；对象长度读不到，状态记为 failed，可稍后「重试元数据补全」';
                        } elseif ($state === \App\Models\Image::STATE_PARTIAL) {
                            $note .= '；部分档位读不到长度，可稍后「重试元数据补全」';
                        }
                        $results[] = ['id' => $id, 'ok' => true, 'note' => $note];
                        continue;
                    }

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

                    // 副本的删除统一交给 finally，否则生成阶段抛异常就会漏掉一整份原图
                    $gen = $this->makeThumbnails($localFile, (string) $row['mime_type'], $path);
                    $thumbUp = $this->uploadThumbs($driver, $gen['thumbs']);
                    $this->recordThumbHashes($id, $thumbUp['hashes'] ?? []);
                    $thumbKeys = $thumbUp['keys'];

                    // 只回填缩略图，process_status 保持 done
                    // COALESCE 保证未重新生成 md 时不会抹掉已有 thumb_path
                    $thumbsValue = \App\Models\Image::encodeThumbs($thumbKeys);
                    $bytesValue  = \App\Models\Image::encodeThumbBytes($thumbUp['bytes']);
                    // 推导必须用更新后的 thumb_path，否则 md 档会被当成缺档、误判 partial
                    $effectiveThumbPath = $thumbKeys['md'] ?? (string) $row['thumb_path'];
                    $state = \App\Models\Image::deriveThumbMeta($thumbsValue, $effectiveThumbPath, $bytesValue);
                    $this->writeThumbMetaState(
                        $pdo,
                        $id,
                        $row['processing_state'] ?? null,
                        $state,
                        ['`thumb_path` = COALESCE(?, `thumb_path`)', '`thumbs` = ?', '`thumb_bytes` = ?'],
                        [$thumbKeys['md'] ?? null, $thumbsValue, $bytesValue]
                    );

                    if ($thumbKeys === []) {
                        // 推导为 skipped，因此不会再被 pending 选中（幂等）
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
                    $this->discardThumbs($gen);
                    $failed++;
                    // 硬失败刻意不写状态：保持 pending 以便下次重试
                    $results[] = ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)];
                } finally {
                    // 副本只有一个归宿：本行结束就删，无论成功、失败还是抛异常
                    if ($isTemp && $localFile !== null) {
                        @unlink($localFile);
                    }
                }
            }

            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$targetCond}{$fromSql}"
            )->fetchColumn();
        }

        $this->json([
            'success' => true,
            'scope' => $scope,
            'total' => $total,
            'remaining' => $remaining,
            'scanned' => $scanned,
            'next_from' => $lastId,
            'done' => $done,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * 写 thumb_meta 状态（三条成功路径的唯一出口，状态 JSON 拼装必须一致）。
     *
     * @param array<int,string> $setFragments 额外 SET 片段（可含 `?`）
     * @param array<int,mixed>  $params       与占位符一一对应
     * @param string            $guard        WHERE `id` = ? 之后的条件
     */
    private function writeThumbMetaState(
        \PDO $pdo,
        int $id,
        ?string $currentJson,
        string $state,
        array $setFragments = [],
        array $params = [],
        string $guard = ''
    ): void {
        $setFragments[] = '`processing_state` = ?';
        $params[] = \App\Models\Image::withProcessingState(
            $currentJson,
            \App\Models\Image::ITEM_THUMB_META,
            $state
        );
        $params[] = $id;
        $pdo->prepare(
            "UPDATE `images` SET " . implode(', ', $setFragments)
            . " WHERE `id` = ?" . ($guard !== '' ? ' ' . $guard : '')
        )->execute($params);
    }

    /** GET /admin/images/queue —— 图片处理管理页（状态总览 + 开始/重试）。 */
    public function queue(Request $request): void
    {
        $status = (string) $request->input('status', 'all');
        if (!in_array($status, ['all', 'pending', 'failed'], true)) {
            $status = 'all';
        }
        $search  = trim((string) $request->input('q', ''));
        $page    = max(1, (int) $request->input('page', '1'));
        $perPage = 50;

        $viewVars = [
            'error' => '',
            'stats' => ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0,
                        'no_thumb' => 0, 'meta_retryable' => 0, 'total' => 0],
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'pageCount' => 1,
            'perPage' => $perPage,
            'status' => $status,
            'search' => $search,
            'queueTotal' => 0,
            'incomingDir' => self::incomingDir(),
        ];

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $viewVars['error'] = '数据库连接失败: ' . $e->getMessage();
            $this->render('admin/queue', $viewVars);
            return;
        }

        // 与 processQueue / queue-stats 同一份快照，页面数字与轮询口径一致
        $stats = $this->queueStatsSnapshot($pdo);

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
            $p = (string) ($r['path'] ?? '');
            $r['temp_exists'] = $p !== '' && is_file($incomingDir . '/' . ltrim($p, '/'));
        }
        unset($r);

        $viewVars['stats'] = $stats;
        $viewVars['rows'] = $rows;
        $viewVars['total'] = $total;
        $viewVars['page'] = $page;
        $viewVars['pageCount'] = $pageCount;
        $viewVars['queueTotal'] = $stats['pending'] + $stats['failed'];
        $this->render('admin/queue', $viewVars);
    }

    /** POST /admin/images/requeue-failed —— 把 failed 的图片重置回 pending 重试。 */
    public function requeueFailed(Request $request): void
    {
        try {
            $pdo = \App\Core\Database::getInstance();
            $n = $pdo->exec("UPDATE `images` SET `process_status` = 'pending' WHERE `process_status` = 'failed'");
            $this->json(['success' => true, 'requeued' => (int) $n]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /admin/images/queue-stats —— 队列统计快照（只读，供队列页轮询）。 */
    public function queueStats(Request $request): void
    {
        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $this->json(['success' => true, 'stats' => $this->queueStatsSnapshot($pdo)]);
    }

    /**
     * POST /admin/images/requeue-one —— 重试单张失败图片。
     *
     * 只允许 failed → pending：done/processing 打不回去，避免把已发布图片打回隐藏态。
     */
    public function requeueOne(Request $request): void
    {
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

    /** 每批上限（云端要逐个下载+上传，批量不宜大）。 */
    private const CONVERT_BATCH_MAX = 20;

    /** 干跑是零 I/O 的纯筛查，批次可以大得多。 */
    private const CONVERT_SCAN_BATCH_MAX = 500;

    /** 可转 WebP 的源格式（GIF 会丢动画、SVG 是矢量）。 */
    private const CONVERTIBLE_MIMES = ['image/jpeg', 'image/png', 'image/bmp', 'image/avif'];

    /**
     * POST /admin/images/convert-originals —— 原图批量转 WebP（干跑优先，按行推进可续跑）。
     * 新对象先 exists() 校验再改库，改库失败删新对象回滚；哈希描述实际存储字节，故一并重算。
     */
    public function convertOriginals(Request $request): void
    {
        $this->jsonFatalGuard('convert-originals');

        $mode = (string) $request->input('mode', 'dry-run');
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            $this->json(['success' => false, 'error' => '无效的转换模式'], 400);
            return;
        }
        $apply = $mode === 'apply';

        // 干跑零 I/O，批次可以大得多；执行每行都要下载+转码+上传，保持小批次
        $batchMax = $apply ? self::CONVERT_BATCH_MAX : self::CONVERT_SCAN_BATCH_MAX;
        $batch = max(1, min($batchMax, (int) $request->input('batch', $apply ? '5' : '200')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        // 干跑每轮从表头重扫（幂等，避免游标越过漏扫）；执行沿用持久化游标，可续跑
        $fromRaw = $request->input('from', null);
        $fromId  = ($fromRaw === null || $fromRaw === '') ? null : max(0, (int) $fromRaw);
        $cursor  = $fromId ?? ($apply ? (int) \App\Models\Setting::get('original_convert_cursor', '0') : 0);

        $stmt = $pdo->prepare(
            "SELECT `id`, `path`, `mime_type`, `storage_profile_id`, `file_sha256` FROM `images`"
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

            // 只换扩展名、布局不动 → 缩略图键不受影响
            $newPath = (string) preg_replace('/\.[a-z0-9]+$/i', '.webp', $path);
            if ($newPath === $path || $newPath === '') {
                $skipped++;
                $notes['键名无法改写'] = ($notes['键名无法改写'] ?? 0) + 1;
                continue;
            }

            if (!$apply) {
                // 干跑零 I/O：不下载不解码（大图会 OOM），是否更小只能在执行时逐张判定 → 这里给的是候选数
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
                $profile = StorageProfile::resolve(
                    $row['storage_profile_id'] !== null ? (int) $row['storage_profile_id'] : null
                );
                $driver = $profile?->driver();
                if ($driver === null) {
                    $failed++;
                    $notes['存储实例不可用'] = ($notes['存储实例不可用'] ?? 0) + 1;
                    continue;
                }

                // 取字节到私有副本：本地也复制一份，绝不在真实存储文件上转码
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

                // 超预算就跳过并如实计数，绝不让它变成不可捕获的 OOM
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

                // 上传新键 → 校验 → 改库 → 删旧，任一步失败即回滚
                $driver->upload($destCopy, $newPath, 'image/webp');
                $uploadedNew = $newPath;
                if (!$driver->exists($newPath)) {
                    throw new \RuntimeException('新对象上传后校验失败');
                }

                $bytes = (int) ($conv['bytes'] ?? 0);
                if ($bytes <= 0) {
                    $bytes = (int) @filesize($destCopy);
                }
                $sha = @hash_file('sha256', $destCopy);

                $upd = $pdo->prepare(
                    "UPDATE `images` SET `path` = ?, `filename` = ?, `mime_type` = 'image/webp',"
                    . " `file_size` = ?, `file_sha256` = ?, `url` = '' WHERE `id` = ?"
                );
                if (!$upd->execute([
                    $newPath,
                    basename($newPath),
                    $bytes,
                    ($sha === false || $sha === '') ? null : $sha,
                    $id,
                ])) {
                    throw new \RuntimeException('数据库更新失败');
                }

                // 产出哈希随字节一起换：旧值只在确实指向本行时才释放，避免误删别人的索引
                try {
                    $newSha = ($sha === false || $sha === '') ? '' : $sha;
                    $oldSha = trim((string) ($row['file_sha256'] ?? ''));
                    if ($newSha !== '') {
                        if ($oldSha !== '' && $oldSha !== $newSha) {
                            $holder = ContentHash::find($oldSha);
                            if ($holder !== null && $holder['image_id'] === $id) {
                                ContentHash::release($oldSha);
                            }
                        }
                        ContentHash::record($id, [ContentHash::KIND_STORED => $newSha]);
                    }
                } catch (\Throwable) {
                    // 索引维护失败不影响转码结果本身
                }

                // 旧对象删失败不影响服务（新对象已就位），计入 orphan 风险
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
                // 两个副本都是自己创建的，一律清理
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

    private const CLEANUP_BATCH_MAX = 50;

    /**
     * POST /admin/images/cleanup-storage —— 清理 storage/incoming 中不再属于在办行的临时文件。
     * pending/processing/failed 的文件一律保留；孤立对象无法发现（无 LIST 能力），需人工清理。
     */
    public function cleanupStorage(Request $request): void
    {
        $this->jsonFatalGuard('cleanup-storage');
        $mode = (string) $request->input('mode', 'dry-run');
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            $this->json(['success' => false, 'error' => '无效的清理模式'], 400);
            return;
        }
        $apply = $mode === 'apply';
        $batch = max(1, min(self::CLEANUP_BATCH_MAX, (int) $request->input('batch', '10')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        try {
            $staging = $this->cleanupStagingFiles($pdo, $batch, $apply);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '清理失败: ' . $e->getMessage()], 500);
            return;
        }

        $this->json([
            'success'   => true,
            'mode'      => $mode,
            'staging'   => $staging,
            'remaining' => $staging['remaining'],
        ]);
    }

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

            // 在办的行一律保留 —— 那个文件可能正被另一个请求使用
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
     * 列出目录下的常规文件（不跟随符号链接，避免越界删除）。
     * @return list<string>
     */
    private function listFilesRecursive(string $dir, int $limit): array
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
     * POST /admin/images/queue-clear —— 清空队列（pending | failed | all），语义是删除数据库记录。
     * 删记录前逐行回收已落桶对象，否则成为云端孤儿；回收失败计入 orphan_risk 并写审计。
     */
    public function queueClear(Request $request): void
    {
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
            "SELECT `id`, `path`, `thumbs`, `thumb_path`, `process_status`, "
            . "`storage_profile_id`, `storage`, `storage_provider` "
            . "FROM `images` WHERE {$cond}"
        )->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            $this->json(['success' => true, 'scope' => $scope, 'deleted' => 0, 'temp_removed' => 0, 'orphan_risk' => 0]);
            return;
        }

        // 原子认领：条件 UPDATE 搬到 processing，与 worker 的认领条件互斥；rowCount=0 即已被抢占
        $claimed = [];
        $claimStmt = $pdo->prepare(
            'UPDATE `images` SET `process_status` = \'processing\', `updated_at` = NOW() '
            . 'WHERE `id` = ? AND `process_status` = ?'
        );
        foreach ($rows as $r) {
            $rid = (int) $r['id'];
            $originalStatus = (string) ($r['process_status'] ?? '');
            try {
                $claimStmt->execute([$rid, $originalStatus]);
                if ($claimStmt->rowCount() > 0) {
                    $claimed[] = $r;
                }
            } catch (\Throwable $e) {
                // 认领失败保守跳过，绝不在无所有权时删对象
                error_log('[MoeRNG] queue-clear 认领失败，跳过 image #' . $rid . ': ' . $e->getMessage());
            }
        }
        $rows = $claimed;
        if ($rows === []) {
            $this->json([
                'success'      => true,
                'scope'        => $scope,
                'deleted'      => 0,
                'temp_removed' => 0,
                'orphan_risk'  => 0,
                'skipped'      => '全部目标行已被处理队列并发认领，未做清理',
            ]);
            return;
        }

        // 先回收对象再删记录：DB 一删，这些 key 就再也无人指认
        $residualKeys = [];   // 尽力回收后仍删不掉的对象 key
        foreach ($rows as $r) {
            $keys = [(string) $r['path']];
            // thumbs JSON 权威解析；thumb_path 仅作 md 兜底
            foreach (\App\Models\Image::decodeThumbMap(
                (string) ($r['thumbs'] ?? ''),
                (string) ($r['thumb_path'] ?? '')
            ) as $thumbKey) {
                if (is_string($thumbKey) && $thumbKey !== '') {
                    $keys[] = $thumbKey;
                }
            }
            $keys = array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
            if ($keys === []) continue;
            try {
                $driver = \App\Models\StorageProfile::driverForImage($r);
            } catch (\Throwable) {
                // 连驱动都解析不出来 → 这行的对象无从回收，全部计入残留
                foreach ($keys as $k) $residualKeys[] = $k;
                continue;
            }
            foreach ($keys as $key) {
                try {
                    $delOk = $driver->delete($key);
                } catch (\Throwable) {
                    $delOk = false;
                }
                if (!$delOk) {
                    // LocalDriver 对「本就不存在」也返回 false（云端幂等返回 true）：
                    // 只有对象确实还在才算残留，否则会谎报
                    try {
                        $stillThere = $driver->exists($key);
                    } catch (\Throwable) {
                        $stillThere = true; // 探测不到时保守按残留计
                    }
                    if ($stillThere) $residualKeys[] = $key;
                }
            }
        }
        $residualKeys = array_values(array_unique($residualKeys));

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

        // 清理临时原图；失败忽略（可能已被前次运行清掉，或云端场景本就不在本地）
        $incomingDir = self::incomingDir();
        $tempRemoved = 0;
        foreach ($rows as $r) {
            $file = $incomingDir . '/' . ltrim((string) $r['path'], '/');
            if (is_file($file)) {
                if (@unlink($file)) {
                    $tempRemoved++;
                }
                @rmdir(dirname($file));
            }
        }

        // orphan_risk 键名沿用（前端 app.js 在读），语义是「尽力回收后仍删失败的对象数」
        $orphanRisk = count($residualKeys);
        if ($residualKeys !== []) {
            try {
                \App\Models\AuditLog::record('image_delete_storage_failed', [
                    'scope' => $scope,
                    'paths' => $residualKeys,
                    'stage' => 'queue_clear_recycle',
                ]);
            } catch (\Throwable) { /* 审计失败不再抛 */ }
        }

        // 操作审计（上面那条只记回收失败的异常事件）
        \App\Models\AuditLog::record('image_queue_clear', [
            'scope' => $scope,
            'deleted' => $deleted,
            'temp_removed' => $tempRemoved,
            'orphan_risk' => $orphanRisk,
        ]);

        $this->json([
            'success' => true,
            'scope' => $scope,
            'deleted' => $deleted,
            'temp_removed' => $tempRemoved,
            'orphan_risk' => $orphanRisk,
        ]);
    }

    /** 历史图片哈希回填（Web 分批）：只算 SHA-256，并同步写进 content_hashes 索引。 */
    public function backfillHashes(Request $request): void
    {
        $this->jsonFatalGuard('backfill-hashes');

        $batchSize = max(1, min(20, (int) $request->input('batch', '5')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        // 「列里没哈希」与「有哈希但没进索引」都要补 —— 后者不算缺失，但同样判不出重复
        $missingCond = "(i.`file_sha256` IS NULL OR i.`file_sha256` = '' OR ch.`sha256` IS NULL)";
        $fromSql = " FROM `images` i LEFT JOIN `content_hashes` ch"
            . " ON ch.`sha256` = i.`file_sha256` AND ch.`image_id` = i.`id`";
        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $remaining = (int) $pdo->query("SELECT COUNT(*)" . $fromSql . " WHERE {$missingCond}")->fetchColumn();

        $updated = 0;
        $failed = 0;
        $results = [];

        if ($remaining > 0) {
            $rows = $pdo->query(
                "SELECT i.`id`, i.`path`, i.`storage_profile_id`, i.`file_sha256`" . $fromSql
                . " WHERE {$missingCond} ORDER BY i.`id` ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $knownSha = trim((string) ($row['file_sha256'] ?? ''));

                // 列里已有强哈希：不必回源，直接建索引
                if ($knownSha !== '') {
                    try {
                        ContentHash::record($id, [ContentHash::KIND_STORED => $knownSha]);
                        $updated++;
                        $results[] = ['id' => $id, 'ok' => true];
                    } catch (\Throwable $e) {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => $e->getMessage()];
                    }
                    continue;
                }

                try {
                    $res = ContentHash::sha256ForImageRow($row);
                    if (!$res['ok']) {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => (string) ($res['error'] ?? '哈希计算失败')];
                        continue;
                    }
                    $sha = (string) $res['sha'];

                    $image = new Image($row);
                    $image->file_sha256 = $sha;
                    if ($image->save()) {
                        $updated++;
                        try {
                            ContentHash::record($id, [ContentHash::KIND_STORED => $sha]);
                        } catch (\Throwable) {
                        }
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

            $remaining = (int) $pdo->query("SELECT COUNT(*)" . $fromSql . " WHERE {$missingCond}")->fetchColumn();
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

    /**
     * POST /admin/images/precheck —— 批量查哈希，前端据此跳过已知内容、不传字节。
     * 只是省流量的优化：真正的判定在 upload() 的抢位里，预检结果过时也不会造成误判。
     */
    public function precheck(Request $request): void
    {
        $raw = $request->input('hashes', []);
        if (!is_array($raw)) {
            $raw = [];
        }
        $this->json(['success' => true, 'hits' => ContentHash::findMany(array_slice($raw, 0, 500))]);
    }

    public function upload(Request $request): void
    {
        $this->jsonFatalGuard('upload');

        $categoryId = $request->input('category_id', '');
        $categoryId = $categoryId !== '' ? (int) $categoryId : null;

        $files = $_FILES['images'] ?? null;
        if (!$files || empty($files['tmp_name'][0])) {
            Session::flash('error', 'No files uploaded.');
            $this->redirect('/admin/images');
        }

        // 未指定或未启用时回退默认实例；没有任何可用实例是硬错误，不做设置兜底
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
        $storage = \App\Models\StorageProfile::cachedDriver($profile);
        $storageType = (string) $profile->driver;
        $storageProvider = (string) $profile->provider;
        $profileId = (int) $profile->id;
        $uploaded = 0;
        $errors = [];
        // 内容级去重：哈希由服务端算，重复则跳过，不影响其余文件
        $duplicates = [];
        $batchHashes = [];

        $fileCount = count($files['tmp_name']);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        for ($i = 0; $i < $fileCount; $i++) {
            $tmpName = $files['tmp_name'][$i];
            // original_name 列是 VARCHAR(255)，严格模式下超长会让整次上传失败；不参与路径构造
            $originalName = mb_substr((string) $files['name'][$i], 0, 255);
            $fileSize = $files['size'][$i];

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "{$originalName}: Upload error code {$files['error'][$i]}";
                continue;
            }

            // 单图大小上限（0 = 不限制，只受 PHP 约束）
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

            $detectedMime = finfo_file($finfo, $tmpName);

            if (!in_array($detectedMime, $this->allowedMimeTypes, true)) {
                $errors[] = "{$originalName}: Invalid file type ({$detectedMime})";
                continue;
            }

            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowedExtensions, true)) {
                $errors[] = "{$originalName}: Invalid file extension (.{$ext})";
                continue;
            }

            // 源字节哈希：判重的唯一依据。落盘哈希会随转码参数变化，不能当键。
            $srcSha256 = $this->sha256File($tmpName);
            if ($srcSha256 === null) {
                $errors[] = "{$originalName}: 无法读取文件内容（哈希计算失败）";
                continue;
            }

            if (isset($batchHashes[$srcSha256])) {
                $duplicates[] = "{$originalName}：与本批次中「{$batchHashes[$srcSha256]}」重复，已跳过";
                continue;
            }

            // 抢位即判重：索引未命中则原子占位；撞唯一键说明并发方抢先（先查后插必然漏）
            $holder = $this->claimHash($srcSha256, ContentHash::KIND_SOURCE);
            if ($holder !== null) {
                $duplicates[] = $this->duplicateNote($originalName, $holder);
                continue;
            }

            // 原图统一转 WebP；失败/不划算则保持原格式，不阻断上传
            $conv = $this->convertOriginalToWebp($tmpName, $detectedMime);
            if ($conv['ok']) {
                $ext = 'webp';
                $detectedMime = 'image/webp';
                clearstatcache(true, $tmpName);
                $fileSize = (int) @filesize($tmpName);
            }

            $dstSha256 = $conv['ok'] ? ($this->sha256File($tmpName) ?? $srcSha256) : $srcSha256;

            // 产出哈希只认「老行只存了落盘字节」与「用户把本站产出的 WebP/缩略图又传回来」
            // 这两种情况；同一张原创图是否重复，由上面的源哈希决定。
            if ($dstSha256 !== $srcSha256) {
                $holder = $this->claimHash($dstSha256, ContentHash::KIND_STORED);
                if ($holder !== null) {
                    ContentHash::release($srcSha256);
                    $duplicates[] = $this->duplicateNote($originalName, $holder);
                    continue;
                }
            }

            // 索引尚未覆盖的历史行：按 file_sha256 列兜底一次，命中即补写索引，此后走快路径。
            $legacy = $this->legacyDuplicateBySha($srcSha256, $dstSha256);
            if ($legacy !== null) {
                ContentHash::release($srcSha256, $dstSha256 !== $srcSha256 ? $dstSha256 : '');
                $duplicates[] = "{$originalName}：与已有图片「{$legacy->original_name}」重复，已跳过";
                continue;
            }

            // 新布局：{yyyy}/{mm}/{uuid}/original.{ext}，缩略图落同目录 thumb-{size}.webp
            $remotePath = \App\Models\Image::newAssetPath($ext);

            try {
                $imgInfo = @getimagesize($tmpName);
                $width = $imgInfo ? $imgInfo[0] : 0;
                $height = $imgInfo ? $imgInfo[1] : 0;

                // 上传只落临时目录即算成功，后续由队列 Worker 生成缩略图并上传最终存储
                $incomingFile = self::incomingDir() . '/' . $remotePath;
                if (!is_dir(dirname($incomingFile))) {
                    @mkdir(dirname($incomingFile), 0755, true);
                }
                if (!@move_uploaded_file($tmpName, $incomingFile)) {
                    throw new \RuntimeException('临时文件写入失败（storage/incoming 不可写）');
                }

                $image = new Image([
                    'filename' => basename($remotePath),
                    'original_name' => $originalName,
                    'path' => $remotePath,
                    'url' => '',
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'file_sha256' => $dstSha256,
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
                    // insert() 遇静默 DB 错误只返回 false；不抛则文件在盘上却没有记录
                    throw new \RuntimeException('数据库写入失败：图片记录未保存（请检查 images 表是否含 storage / storage_provider 列）');
                }
                $uploaded++;

                // 成功才登记批内去重表：失败的文件本批内还允许再试一次
                $batchHashes[$srcSha256] = $originalName;

                // 占位转正：索引行指向真实图片，之后命中即可直接返回这张图
                ContentHash::attach($srcSha256, (int) $image->id);
                if ($dstSha256 !== $srcSha256) {
                    ContentHash::attach($dstSha256, (int) $image->id);
                }

            } catch (\Throwable $e) {
                // 落库失败必须把占位还回去，否则这张图从此再也传不上来
                ContentHash::release($srcSha256, $dstSha256 !== $srcSha256 ? $dstSha256 : '');
                $errors[] = "{$originalName}: Storage error - {$e->getMessage()}";
            }
        }

        // AJAX 上传必须回 JSON：盲目跟随 302 会消耗掉 flash，前端什么都看不到
        $dupCount = count($duplicates);
        if ($this->isAjax()) {
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
            // flash 在 layout 里原样输出 HTML，而错误信息含原始文件名（可构造 HTML）→ 必须逐条转义
            Session::flash('error', implode('<br>', array_map('h', $errors)));
        }

        $this->redirect('/admin/images');
    }

    public function update(Request $request): void
    {
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
        $id = (int) $request->input('id');
        $image = Image::find($id);

        if (!$image) {
            $this->fail('图片不存在。', 404, self::PAGE);
        }

        if (!$image->delete()) {
            $this->fail('图片删除失败。', 500, self::PAGE);
        }

        // image_delete 审计由 Image::delete()（模型层）唯一承担，此处重复埋点会变成 N+1 条
        $this->ok('已删除 1 张图片。', ['deleted' => [$id]], self::PAGE);
    }

    public function batchDelete(Request $request): void
    {
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

        \App\Models\AuditLog::record('image_batch_delete', [
            'count' => count($deleted),
            'ids' => $deleted,
            'failed' => $failed,
        ]);

        $message = '已删除 ' . count($deleted) . ' 张图片。';
        if (!empty($failed)) {
            $message .= ' ' . count($failed) . ' 张删除失败。';
        }

        $this->ok($message, ['deleted' => $deleted, 'failed' => $failed], self::PAGE);
    }

    /** POST /admin/images/batch-categorize —— 批量改分类，category_id 0 表示清空分类。 */
    public function batchCategorize(Request $request): void
    {
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
            // 必须赋值后 save()：Model::update() 是无参 protected 方法，外部调用会抛 Error
            $image->category_id = $categoryId === 0 ? null : $categoryId;
            if ($image->save()) {
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
