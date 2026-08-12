<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Database;
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

        // 存储用量（v1.2.0 迭代）
        $usageRow = Database::getInstance()
            ->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS total_bytes,
                            COALESCE(AVG(file_size), 0) AS avg_bytes,
                            COALESCE(MAX(file_size), 0) AS max_bytes
                     FROM `images` WHERE status = 'active'")
            ->fetch(\PDO::FETCH_ASSOC);
        $usage = [
            'count'       => (int) ($usageRow['cnt'] ?? 0),
            'total_bytes' => (float) ($usageRow['total_bytes'] ?? 0),
            'avg_bytes'   => (float) ($usageRow['avg_bytes'] ?? 0),
            'max_bytes'   => (float) ($usageRow['max_bytes'] ?? 0),
        ];

        // 7 天上传趋势（v1.2.0 迭代）
        $trend = [];
        $trendRows = Database::getInstance()
            ->query("SELECT DATE(created_at) AS d, COUNT(*) AS c
                     FROM `images`
                     WHERE status = 'active' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                     GROUP BY DATE(created_at) ORDER BY d ASC")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $byDay = [];
        foreach ($trendRows as $row) {
            $byDay[$row['d']] = (int) $row['c'];
        }
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = ['day' => date('m-d', strtotime($day)), 'count' => $byDay[$day] ?? 0];
        }

        // 最近操作日志（v1.2.0 迭代）
        $logs = [];
        try {
            $logRows = Database::getInstance()
                ->query("SELECT username, action, detail, created_at FROM `audit_logs`
                         ORDER BY id DESC LIMIT 8")
                ->fetchAll(\PDO::FETCH_ASSOC);
            $actionLabels = [
                'login_success' => '登录',
                'login_fail'    => '登录失败',
                'settings_update' => '设置更新',
                'image_upload'  => '上传图片',
                'image_delete'  => '删除图片',
                'category_create' => '新建分类',
                'category_update' => '编辑分类',
                'category_delete' => '删除分类',
                'apikey_create' => '创建密钥',
                'apikey_delete' => '删除密钥',
            ];
            foreach ($logRows as $row) {
                $logs[] = [
                    'username' => (string) ($row['username'] ?? '-'),
                    'action'   => $actionLabels[$row['action']] ?? $row['action'],
                    'detail'   => (string) ($row['detail'] ?? ''),
                    'time'     => (string) ($row['created_at'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            $logs = [];
        }

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
            'title'        => '仪表盘',
            'stats'        => $stats,
            'recentImages' => $recentImages,
            'categoryStats'=> $categoryStats,
            'usage'        => $usage,
            'trend'        => $trend,
            'logs'         => $logs,
            'system'       => $system,
        ]);
    }
}
