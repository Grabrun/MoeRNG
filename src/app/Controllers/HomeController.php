<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Config;
use App\Models\Image;
use App\Models\Category;

class HomeController extends Controller
{
    public function index(Request $request): void
    {
        // v1.2.0 迭代: site visit counter (best effort, never breaks the page).
        \App\Core\Stats::bump(\App\Core\Stats::TABLE_VISITS);

        $siteName = Config::get('settings.site_name', 'MoeRNG');
        $siteSlogan = Config::get('settings.site_slogan', '随机二次元图片 API 服务');
        $logoUrl = Config::get('settings.logo_url', '') ?: '/assets/logo.png';
        // v1.2.1 性能优化: 统计数字 60s 短缓存（文件缓存，避免每次请求都跑 COUNT）。
        // 对用户无感（60s 内的数字变化不影响体验），DB 压力降至 1/60。
        $totalImages = 0;
        $totalCategories = 0;
        $cacheFile = __DIR__ . '/../../../var/cache/home_stats.php';
        $cacheTtl = 60;
        $cacheData = null;
        try {
            if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
                $cacheData = include $cacheFile;
                $cacheData = is_array($cacheData) ? $cacheData : null;
            }
        } catch (\Throwable) {}

        if ($cacheData !== null) {
            $totalImages = (int) ($cacheData['images'] ?? 0);
            $totalCategories = (int) ($cacheData['categories'] ?? 0);
        } else {
            try {
                $totalImages = Image::count("status = 'active'");
                $totalCategories = Category::count();
                $dir = dirname($cacheFile);
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $payload = "<?php return " . var_export(['images' => $totalImages, 'categories' => $totalCategories], true) . ";";
                @file_put_contents($cacheFile, $payload, LOCK_EX);
            } catch (\Throwable) {
                // DB may not be available — skip caching, fall back to zero.
            }
        }

        $categories = [];
        try {
            $categories = Category::all('sort_order ASC');
        } catch (\Throwable) {}

        $this->render('home', [
            'siteName' => $siteName,
            'siteSlogan' => $siteSlogan,
            'logoUrl' => $logoUrl,
            'totalImages' => $totalImages,
            'totalCategories' => $totalCategories,
            'categories' => $categories,
            'baseUrl' => Config::get('app.base_url', ''),
            // v1.1.0-beta.4: footer site info (editable via 系统设置).
            'siteDescription' => Config::get('settings.site_description', ''),
            'icpNumber' => Config::get('settings.icp_number', ''),
            'copyright' => str_replace(
                '{year}',
                date('Y'),
                (string) Config::get('settings.copyright', '© {year} MoeRNG. All rights reserved.')
            ),
            'footerHtml' => Config::get('settings.footer_html', ''),
            // v1.1.0-beta.8: GitHub repo link (nav + about), configured in 系统设置.
            'githubUrl' => Config::get('settings.github_url', ''),
        ]);
    }
}
