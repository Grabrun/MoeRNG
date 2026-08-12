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
        $siteName = Config::get('settings.site_name', 'MoeRNG');
        $siteSlogan = Config::get('settings.site_slogan', '随机二次元图片 API 服务');
        $logoUrl = Config::get('settings.logo_url', '') ?: '/assets/logo.png';
        $totalImages = 0;
        $totalCategories = 0;

        try {
            $totalImages = Image::count("status = 'active'");
            $totalCategories = Category::count();
        } catch (\Throwable) {
            // DB may not be available
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
