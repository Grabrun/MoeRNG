<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Image;
use App\Models\Category;
use App\Models\User;
use App\Models\ApiKey;
use App\Models\StorageProfile;

class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $recentImages = Image::paginate(1, 6, 'id DESC', "status = 'active'")['data'];

        // 分类分布：扁平树 + 每类图片数
        $categoryStats = [];
        foreach (Category::getFlatTree() as $cat) {
            $categoryStats[] = [
                'id'    => (int) $cat->id,
                'name'  => (string) $cat->name,
                'slug'  => (string) $cat->getSlug(),
                'count' => Image::count('category_id = ?', [(int) $cat->id]),
            ];
        }
        usort($categoryStats, fn ($a, $b) => $b['count'] <=> $a['count']);

        // 存储概览
        $storage = null;
        $default = StorageProfile::defaultProfile();
        if ($default) {
            $storage = [
                'name'     => (string) $default->name,
                'driver'   => (string) ($default->driver ?? 'local'), // local | s3
                'provider' => (string) ($default->provider ?? ''),
                'is_local' => !$default->isS3(),
            ];
        }

        // 系统信息
        $system = [
            'php'      => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
            'storage'  => $storage,
        ];

        $stats = [
            'total_images'     => Image::count("status = 'active'"),
            'total_categories' => Category::count(),
            'total_users'      => User::count(),
            'total_api_keys'   => ApiKey::count(),
            'active_api_keys'  => ApiKey::count("status = 'active'"),
            'unused_images'    => Image::count("status = 'active' AND category_id IS NULL"),
        ];

        $this->render('admin/dashboard', [
            'title'      => '仪表盘',
            'stats'      => $stats,
            'recentImages' => $recentImages,
            'categoryStats' => $categoryStats,
            'system'     => $system,
        ]);
    }
}
