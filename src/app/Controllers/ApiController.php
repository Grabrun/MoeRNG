<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Image;
use App\Models\Category;

class ApiController extends Controller
{
    /**
     * v1.5.0-beta.2（API 缩略图获取）: `size` 的允许取值 = 缩略图三档 + original。
     * `original` 是显式表达"我要原图"的写法（redirect 模式的既有语义就是原图）。
     */
    private const SIZE_ORIGINAL = 'original';

    /** /images 的私有短缓存秒数（结果由 page/limit/category/size 决定，非随机）。 */
    private const LIST_CACHE_SECONDS = 30;

    /**
     * v1.2.1 迭代: dynamic CORS from settings.cors_origins (comma-separated
     * origin whitelist). Empty = no CORS header (browser default same-origin).
     */
    protected function json(mixed $data, int $status = 200): never
    {
        try {
            $origins = trim((string) \App\Models\Setting::get('cors_origins', ''));
            if ($origins !== '') {
                $allowed = array_map('trim', explode(',', $origins));
                $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
                if ($origin !== '' && in_array($origin, $allowed, true)) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
                    header('Vary: Origin');
                }
            }
        } catch (\Throwable) {
            // settings table may be missing during install — ignore
        }
        parent::json($data, $status);
    }

    /**
     * 解析 `size` 参数（白名单：缩略图三档 + `original`）。
     *
     * 非法值**不静默回退**——静默回退会让调用方以为拿到了自己要的尺寸；
     * 直接 400 并列出合法值，问题在第一次调用就暴露。
     *
     * @param string $default 缺省值（random+redirect 用 original 保持既有语义，
     *                        其余用 Image::THUMB_DEFAULT = md）
     * @param string|null $error 非法时写入人读原因
     * @return string|null null = 非法
     */
    private function parseSize(Request $request, string $default, ?string &$error): ?string
    {
        $raw = strtolower(trim((string) $request->input('size', '')));
        if ($raw === '') {
            return $default;
        }
        if ($raw === self::SIZE_ORIGINAL || isset(Image::THUMB_SIZES[$raw])) {
            return $raw;
        }
        $error = 'Invalid size: must be one of '
            . implode(', ', array_merge(array_keys(Image::THUMB_SIZES), [self::SIZE_ORIGINAL]));
        return null;
    }

    /**
     * 单张图片的 API 载荷 —— random 与 list **共用同一份**，字段与语义不会漂移。
     *
     * v1.5.0-beta.2（口径修正）：**请求什么图就返回什么** —— 响应里只有一个地址
     * 字段 `url`，它是按 `size` 解析后的那个地址（`size` 缺省即原图）。
     *
     * 此前还附带 `thumb` / `thumbs` / `thumb_size`，现已整体移除：
     *   - 每个预签名 URL 约 450 字节，四个字段 ≈ 1.8 KB —— 对"要一张图"的调用方是纯负担；
     *   - `size=original` 时 `thumb` 与 `url` 完全重复；
     *   - 要别的尺寸就改 `size` 再请求一次，比"一次给一串让你自己挑"更直白。
     *
     * **`url` 的含义随 `size` 变化**（这正是本方法的设计意图）；不传 `size` 时它是
     * 原图，因此对既有调用方而言 `url` 字段的含义**没有变**。
     *
     * **响应里所有字段都描述 `url` 指向的那一张图**（用户口径）：
     *
     *   - `url`  —— 按 `size` 解析后的地址（`size` 缺省即原图）；
     *   - `size` —— **实际生效**的尺寸（回退过就写回退后的值，回退到原图写 `original`）。
     *     极短，保留它是因为没有它调用方无法察觉"我要 sm、实际给的是 md"；
     *   - `width` / `height` —— 该图的宽高。缩略图宽高由 `Image::thumbDimensions()` 推导，
     *     与生成端**同一份实现**，所以报的就是实际生成的那张图的尺寸；
     *   - `mime_type` —— 从缩略图键的扩展名推导（`thumbKey()` 统一生成 `.webp`）；
     *     不写死 `image/webp`，将来换编码格式时这里不会说谎，认不出则报 `null` 而不是猜；
     *   - `file_size` —— **该图的实测字节数**：原图取记录值，缩略图取生成时实测入库的值
     *     （`thumb_bytes` 列）。存量行尚未补全字节数时为 `null`（"未知"）——
     *     既不填原图的值（那是谎报），也不为它多打一次对象存储往返；
     *     跑一次「补全缩略图」即可补齐。
     */
    private function imagePayload(Image $img, string $size): array
    {
        $resolved = $img->displayUrlWithSize($size);
        // 链走到底回退到原图时 displayUrlWithSize 给 null —— 这里统一成 'original'
        $actual = $resolved['size'] ?? self::SIZE_ORIGINAL;
        $isOrig = $actual === self::SIZE_ORIGINAL;

        $w = (int) $img->width;
        $h = (int) $img->height;
        $dims = $isOrig
            ? (($w > 0 && $h > 0) ? [$w, $h] : null)
            : Image::thumbDimensions($w, $h, Image::THUMB_SIZES[$actual]);

        $thumbExt = strtolower(pathinfo($img->thumbKeyFor($actual), PATHINFO_EXTENSION));

        return [
            'id' => $img->id,
            'url' => $resolved['url'],
            'size' => $actual,
            'width' => $dims[0] ?? null,
            'height' => $dims[1] ?? null,
            'mime_type' => $isOrig ? $img->mime_type : ($thumbExt === 'webp' ? 'image/webp' : null),
            'file_size' => $isOrig ? $img->file_size : $img->thumbBytesFor($actual),
            'category' => $img->category() ? $img->category()->name : null,
        ];
    }

    /**
     * GET /api/v1/random
     * Get a random image
     *
     * v1.5.0-beta.2: 新增 `size`（`sm` / `md` / `lg` / `original`）——
     *   - **请求什么图就返回什么**：`url` 就是按 `size` 解析后的地址；
     *   - `size` 缺省 = `original`（原图）→ 不传 `size` 时 `url` 仍是原图，
     *     对既有调用方而言 `url` 字段的含义没有变；
     *   - redirect 的目标同样由 `size` 决定，缺省仍是原图（既有语义一字不改）；
     *   - 响应不再附带 `thumb` / `thumbs` / `thumb_size`（见 imagePayload 注释）；
     *   - 响应带一个极短的 `size` 字段，如实回报**实际生效**尺寸 ——
     *     没有它，请求 `sm` 而该档未生成时会静默回退到 md/原图，调用方无从察觉；
     *   - 随机端点带 `Cache-Control: no-store`：URL 固定但语义是"每次换一张"，
     *     一旦允许缓存，随机性会在缓存期内整体失效（刷新也拿同一张）。
     */
    public function random(Request $request): void
    {
        $category = $request->input('category', '');
        $type = $request->input('type', 'json');

        // Determine return type
        $returnType = $type;
        if ($request->wantsJson()) {
            $returnType = 'json';
        }

        // 缺省 = 原图（JSON 与 redirect 一致）—— 这不只是"保守"：
        // `url` 现在承载"请求的那张图"，缺省若是 md，不传 size 的既有调用方
        // 会从"拿到原图"变成"拿到缩略图"，那才是真正的破坏性变更。
        $sizeError = null;
        $size = $this->parseSize($request, self::SIZE_ORIGINAL, $sizeError);
        if ($size === null) {
            $this->json(['success' => false, 'error' => $sizeError], 400);
        }

        // Find category
        $categoryId = null;
        if ($category) {
            $cat = Category::getBySlug($category);
            if ($cat) {
                $categoryId = (int) $cat->id;
            }
        }

        $image = Image::random($categoryId);

        if (!$image) {
            $this->json([
                'success' => false,
                'error' => 'No images found',
                'message' => 'No images available' . ($category ? " in category '{$category}'" : '') . '.',
            ], 404);
        }

        // 随机端点**必须禁止缓存** —— URL 固定、语义却是"每次换一张"，
        // 一旦被缓存，随机性会在缓存期内整体失效（刷新也拿同一张）。
        // 放在分支之前：JSON 与 redirect 两条路径都要带，只写一处不会漏。
        header('Cache-Control: no-store, max-age=0');

        if ($returnType === 'redirect') {
            $resolved = $image->displayUrlWithSize($size);
            (new Response())->redirect($resolved['url'], 302);
        }

        $this->json([
            'success' => true,
            'data' => $this->imagePayload($image, $size),
        ]);
    }

    /**
     * GET /api/v1/images
     * List images (paginated)
     *
     * v1.5.0-beta.2: 与 /random 共用同一套 `size` 语义（列表页是缩略图最大消费者）。
     * 本端点内容由 page/limit/category/size 决定、**没有随机性**，故允许私有短缓存。
     */
    public function list(Request $request): void
    {
        $page = max(1, (int) $request->input('page', '1'));
        $perPage = min(100, max(1, (int) $request->input('limit', '20')));
        $category = $request->input('category', '');

        $sizeError = null;
        $size = $this->parseSize($request, self::SIZE_ORIGINAL, $sizeError);
        if ($size === null) {
            $this->json(['success' => false, 'error' => $sizeError], 400);
        }

        $where = "status = 'active'";
        $params = [];

        if ($category) {
            $cat = Category::getBySlug($category);
            if ($cat) {
                $ids = Image::getCategoryAndChildIds((int) $cat->id);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where .= " AND category_id IN ({$placeholders})";
                $params = $ids;
            }
        }

        $result = Image::paginate($page, $perPage, 'sort_order ASC, id DESC', $where, $params);

        $data = array_map(
            fn (Image $img) => $this->imagePayload($img, $size),
            $result['data']
        );

        header('Cache-Control: private, max-age=' . self::LIST_CACHE_SECONDS);

        $this->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
            ],
        ]);
    }

    /**
     * GET /api/v1/categories
     * List categories
     */
    public function categories(Request $request): void
    {
        $tree = Category::getTree();
        $this->json([
            'success' => true,
            'data' => $tree,
        ]);
    }

    /**
     * GET /api/v1/stats
     * Get API statistics
     */
    public function stats(Request $request): void
    {
        $totalImages = Image::count("status = 'active'");
        $totalCategories = Category::count();

        // v1.2.1 security: version / storage_driver removed from the public
        // surface — exact build & storage-provider details help attackers
        // target known CVEs. Keep only business counters.
        $this->json([
            'success' => true,
            'data' => [
                'total_images' => $totalImages,
                'total_categories' => $totalCategories,
            ],
        ]);
    }

}
