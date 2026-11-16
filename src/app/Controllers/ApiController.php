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
    /** 必须与 Image::displayUrlWithSize 认同同一字符串，否则请求原图会落到 md。 */
    private const SIZE_ORIGINAL = Image::SIZE_ORIGINAL;

    private const LIST_CACHE_SECONDS = 30;

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
        }
        parent::json($data, $status);
    }

    /** 非法 size 不静默回退，直接 400 并列出合法值。 */
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

    /** 响应所有字段都描述 url 指向的那张图；size 回报实际生效尺寸。 */
    private function imagePayload(Image $img, string $size): array
    {
        $resolved = $img->displayUrlWithSize($size);
        // displayUrlWithSize 回退到原图时给 null，这里统一成 original。
        $actual = $resolved['size'] ?? self::SIZE_ORIGINAL;
        $isOrig = $actual === self::SIZE_ORIGINAL;

        $w = (int) $img->width;
        $h = (int) $img->height;
        $dims = $isOrig
            ? (($w > 0 && $h > 0) ? [$w, $h] : null)
            : Image::thumbDimensions($w, $h, Image::THUMB_SIZES[$actual]);

        $thumbExt = strtolower(pathinfo($img->thumbKeyFor($actual), PATHINFO_EXTENSION));

        $cat = $img->category();

        return [
            'id' => $img->id,
            'url' => $resolved['url'],
            'size' => $actual,
            'width' => $dims[0] ?? null,
            'height' => $dims[1] ?? null,
            'mime_type' => $isOrig ? $img->mime_type : ($thumbExt === 'webp' ? 'image/webp' : null),
            'file_size' => $isOrig ? $img->file_size : $img->thumbBytesFor($actual),
            'category' => $cat ? $cat->name : null,
        ];
    }

    /** GET /api/v1/random：size 缺省为 original，保持既有调用方拿原图。 */
    public function random(Request $request): void
    {
        $category = $request->input('category', '');
        $type = $request->input('type', 'json');

        if ($type !== '' && !in_array($type, ['json', 'redirect'], true)) {
            $this->json([
                'success' => false,
                'error'   => 'Invalid value for "type". Allowed values: json, redirect.',
                'allowed' => ['json', 'redirect'],
            ], 400);
        }
        $returnType = $type !== '' ? $type : 'json';
        if ($request->wantsJson()) {
            $returnType = 'json';
        }

        // 缺省原图，避免不传 size 的既有调用方从原图变成缩略图。
        $sizeError = null;
        $size = $this->parseSize($request, self::SIZE_ORIGINAL, $sizeError);
        if ($size === null) {
            $this->json(['success' => false, 'error' => $sizeError], 400);
        }

        // slug 不存在时显式 404，不静默放宽成返回全量。
        $categoryId = null;
        if ($category !== '' && $category !== null) {
            $cat = Category::getBySlug((string) $category);
            if (!$cat) {
                $this->json([
                    'success' => false,
                    'error'   => 'Category not found',
                    'message' => '分类不存在：' . htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8'),
                    'code'    => 'CATEGORY_NOT_FOUND',
                ], 404);
            }
            $categoryId = (int) $cat->id;
        }

        $image = Image::random($categoryId);

        if (!$image) {
            $this->json([
                'success' => false,
                'error' => 'No images found',
                'code' => 'NO_IMAGES_AVAILABLE',
                'message' => '图库暂无可用图片'
                    . ($category ? '（分类 ' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . ' 下暂无图片）' : '')
                    . '，请先在管理后台「图片管理」上传图片。',
            ], 404);
        }

        // 随机端点必须禁止缓存，否则缓存期内刷新也拿同一张。
        header('Cache-Control: no-store, max-age=0');

        if ($returnType === 'redirect') {
            $resolved = $image->displayUrlWithSize($size);
            // 地址为空时按 404 处理，避免 302 到空 Location。
            if (($resolved['url'] ?? '') === '') {
                $this->json([
                    'success' => false,
                    'error'   => 'Image unavailable',
                    'message' => '该图片的可访问地址缺失（存储路径为空），请在管理后台检查该图片的存储配置。',
                    'code'    => 'IMAGE_URL_MISSING',
                ], 404);
            }
            (new Response())->redirect($resolved['url'], 302);
        }

        $this->json([
            'success' => true,
            'data' => $this->imagePayload($image, $size),
        ]);
    }

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

        // 只暴露已处理完成的图，与 /random 的可见性口径一致。
        $where = "status = 'active' AND process_status = 'done'";
        $params = [];

        if ($category !== '' && $category !== null) {
            $cat = Category::getBySlug((string) $category);
            if (!$cat) {
                $this->json([
                    'success' => false,
                    'error'   => 'Category not found',
                    'message' => '分类不存在：' . htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8'),
                    'code'    => 'CATEGORY_NOT_FOUND',
                ], 404);
            }
            $ids = Image::getCategoryAndChildIds((int) $cat->id);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND category_id IN ({$placeholders})";
            $params = $ids;
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

    public function categories(Request $request): void
    {
        $tree = Category::getTree();
        $this->json([
            'success' => true,
            'data' => $tree,
        ]);
    }

    public function stats(Request $request): void
    {
        // total_images 只统计 process_status='done'，与 /images、/random 同口径。
        $totalImages = Image::count("status = 'active' AND process_status = 'done'");
        $totalCategories = Category::count();

        $this->json([
            'success' => true,
            'data' => [
                'total_images' => $totalImages,
                'total_categories' => $totalCategories,
            ],
        ]);
    }

}
