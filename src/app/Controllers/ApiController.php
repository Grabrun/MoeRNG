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
     * GET /api/v1/random
     * Get a random image
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

        if ($returnType === 'redirect') {
            $response = new Response();
            $response->redirect($image->url(), 302);
        }

        // JSON response
        $this->json([
            'success' => true,
            'data' => [
                'id' => $image->id,
                'url' => $image->url(),
                'width' => $image->width,
                'height' => $image->height,
                'mime_type' => $image->mime_type,
                'file_size' => $image->file_size,
                'category' => $image->category() ? $image->category()->name : null,
            ],
        ]);
    }

    /**
     * GET /api/v1/images
     * List images (paginated)
     */
    public function list(Request $request): void
    {
        $page = max(1, (int) $request->input('page', '1'));
        $perPage = min(100, max(1, (int) $request->input('limit', '20')));
        $category = $request->input('category', '');

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

        $data = array_map(function (Image $img) {
            return [
                'id' => $img->id,
                'url' => $img->url(),
                'width' => $img->width,
                'height' => $img->height,
                'mime_type' => $img->mime_type,
                'file_size' => $img->file_size,
                'category' => $img->category() ? $img->category()->name : null,
            ];
        }, $result['data']);

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

        $this->json([
            'success' => true,
            'data' => [
                'total_images' => $totalImages,
                'total_categories' => $totalCategories,
                'version' => defined('APP_VERSION') ? APP_VERSION : '1.1.0-beta.9',
                'storage_driver' => self::activeStorageDriver(),
            ],
        ]);
    }

    /**
     * v1.0.35: storage state comes from storage_profiles, not settings.
     * Returns the default profile's driver (local | s3:{provider}), or null
     * when no usable profile exists.
     */
    private static function activeStorageDriver(): ?string
    {
        $profile = \App\Models\StorageProfile::defaultProfile();
        if ($profile === null) {
            return null;
        }
        return $profile->isS3()
            ? 's3:' . (string) $profile->provider
            : 'local';
    }
}
