<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Database;
use App\Core\Stats;
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

        $countsByCat = [];
        try {
            $rows = Database::getInstance()
                ->query("SELECT category_id, COUNT(*) AS c FROM `images` WHERE status = 'active' GROUP BY category_id")
                ->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $countsByCat[(int) $r['category_id']] = (int) $r['c'];
            }
        } catch (\Throwable) {
        }

        $categoryStats = [];
        foreach (Category::getFlatTree() as $cat) {
            $categoryStats[] = [
                'id'    => (int) $cat->id,
                'name'  => (string) $cat->name,
                'slug'  => (string) $cat->getSlug(),
                'count' => $countsByCat[(int) $cat->id] ?? 0,
            ];
        }
        usort($categoryStats, fn ($a, $b) => $b['count'] <=> $a['count']);

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

        $storage = null;
        $default = StorageProfile::defaultProfile();
        if ($default) {
            $storage = [
                'name'     => (string) $default->name,
                'driver'   => (string) ($default->driver ?? 'local'),
                'provider' => (string) ($default->provider ?? ''),
                'is_local' => !$default->isS3(),
            ];
        }

        // PHP 某些环境会返回 PRC（Asia/Shanghai 的别名），统一显示规范名。
        $system = [
            'php'      => PHP_VERSION,
            'timezone' => (date_default_timezone_get() === 'PRC') ? 'Asia/Shanghai' : date_default_timezone_get(),
            'storage'  => $storage,
        ];

        $apiStats    = Stats::summary(Stats::TABLE_API);
        $visitStats  = Stats::summary(Stats::TABLE_VISITS);
        $apiSeries   = Stats::series(Stats::TABLE_API);
        $visitSeries = Stats::series(Stats::TABLE_VISITS);

        $status = ['cpu' => null, 'mem' => null, 'disk' => null, 'php_mem' => null, 'mem_limit' => null];
        if (function_exists('sys_getloadavg')) {
            $la = @sys_getloadavg();
            if (is_array($la)) {
                // 宝塔禁用 shell_exec，核心数改从 /proc/cpuinfo 统计。
                $cpuInfo = @file_get_contents('/proc/cpuinfo');
                $cores = ($cpuInfo !== false) ? max(1, substr_count($cpuInfo, 'processor')) : 1;
                $status['cpu'] = round(min(100, $la[0] / $cores * 100), 1);
            }
        }
        $status['php_mem']  = memory_get_usage(true);
        $status['mem_limit'] = (string) ini_get('memory_limit');
        $limitBytes = -1;
        $lim = trim($status['mem_limit']);
        if ($lim !== '' && $lim !== '-1') {
            $unit = strtolower(substr($lim, -1));
            $num = (int) $lim;
            if ($unit === 'g') $limitBytes = $num * 1024 * 1024 * 1024;
            elseif ($unit === 'm') $limitBytes = $num * 1024 * 1024;
            elseif ($unit === 'k') $limitBytes = $num * 1024;
            else $limitBytes = $num;
        }
        if ($limitBytes > 0) {
            $status['mem'] = round($status['php_mem'] / $limitBytes * 100, 1);
        }
        if ($status['mem'] === null) {
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo !== false && preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m) && preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m2)) {
                $status['mem'] = round(100 - (float) $m2[1] / (float) $m[1] * 100, 1);
            }
        }
        $diskFree = @disk_free_space(dirname(__DIR__, 2));
        $diskTotal = @disk_total_space(dirname(__DIR__, 2));
        if ($diskFree !== false && $diskTotal !== false && $diskTotal > 0) {
            $status['disk'] = round(100 - $diskFree / $diskTotal * 100, 1);
        }

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
            'apiStats'     => $apiStats,
            'visitStats'   => $visitStats,
            'apiSeries'    => $apiSeries,
            'visitSeries'  => $visitSeries,
            'status'       => $status,
        ]);
    }
}
