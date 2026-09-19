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
     * `thumb` 取解析后的直链（尺寸不存在时按 请求尺寸 → md → 原图 兜底，**永不为空**），
     * `thumb_size` 如实回报**实际生效**的尺寸（`original` 表示回退到了原图），
     * `thumbs` 仍只含**真实存在**的尺寸映射。
     */
    private function imagePayload(Image $img, string $size): array
    {
        $resolved = $img->displayUrlWithSize($size);
        return [
            'id' => $img->id,
            'url' => $img->url(),
            'thumb' => $resolved['url'],
            'thumb_size' => $resolved['size'] ?? self::SIZE_ORIGINAL,
            'thumbs' => $img->thumbUrls(),
            'width' => $img->width,
            'height' => $img->height,
            'mime_type' => $img->mime_type,
            'file_size' => $img->file_size,
            'category' => $img->category() ? $img->category()->name : null,
        ];
    }

    /**
     * GET /api/v1/random
     * Get a random image
     *
     * v1.5.0-beta.2: 新增 `size`（`sm` / `md` / `lg` / `original`）——
     *   - `size` 只影响 `thumb` 字段与 redirect 的目标；`url` 始终是原图；
     *   - **不传 `size` 时 redirect 仍指向原图**（既有语义一字不改）；
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

        // redirect 缺省 = 原图（向后兼容）；JSON 缺省 = md（与既有 thumb 字段一致）
        $sizeError = null;
        $size = $this->parseSize(
            $request,
            $returnType === 'redirect' ? self::SIZE_ORIGINAL : Image::THUMB_DEFAULT,
            $sizeError
        );
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
        $size = $this->parseSize($request, Image::THUMB_DEFAULT, $sizeError);
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
