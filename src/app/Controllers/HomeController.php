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
    private function frontData(): array
    {
        return [
            'siteName' => Config::get('settings.site_name', 'MoeRNG'),
            'siteSlogan' => Config::get('settings.site_slogan', '随机二次元图片 API 服务'),
            'logoUrl' => Config::get('settings.logo_url', '') ?: '/assets/logo.png',
            'baseUrl' => Config::get('app.base_url', ''),
            'siteDescription' => Config::get('settings.site_description', ''),
            'icpNumber' => Config::get('settings.icp_number', ''),
            'copyright' => str_replace(
                '{year}',
                date('Y'),
                (string) Config::get('settings.copyright', '© {year} MoeRNG. All rights reserved.')
            ),
            'footerHtml' => Config::get('settings.footer_html', ''),
            'githubUrl' => Config::get('settings.github_url', ''),
            'categories' => $this->safeCategories(),
        ];
    }

    private function safeCategories(): array
    {
        try {
            return Category::all('sort_order ASC');
        } catch (\Throwable) {
            return [];
        }
    }

    public function index(Request $request): void
    {
        \App\Core\Stats::bump(\App\Core\Stats::TABLE_VISITS);

        $data = $this->frontData();
        $data['totalImages'] = 0;
        $data['totalCategories'] = 0;
        $data['availability'] = null;
        try {
            $data['totalImages'] = Image::count("status = 'active'");
            $data['totalCategories'] = Category::count();
            // 近 7 天 API 成功率：4xx 视为可用，5xx 计失败。
            $data['availability'] = \App\Core\Stats::availability();
        } catch (\Throwable) {
        }

        $this->render('home', $data);
    }

    public function docs(Request $request): void
    {
        $this->render('docs', $this->frontData() + [
            'activePage' => 'docs',
            'pageTitle' => 'API 文档 - ' . Config::get('settings.site_name', 'MoeRNG'),
        ]);
    }

    public function tester(Request $request): void
    {
        $this->render('tester', $this->frontData() + [
            'activePage' => 'tester',
            'pageTitle' => '在线测试 - ' . Config::get('settings.site_name', 'MoeRNG'),
        ]);
    }

    public function gallery(Request $request): void
    {
        $sections = [];
        try {
            foreach ($this->safeCategories() as $cat) {
                $images = Image::randomBatch((int) $cat->id, 12);
                if ($images) {
                    $sections[] = ['name' => $cat->name, 'images' => $images];
                }
            }
            $uncategorized = Image::randomBatch(null, 12);
            if ($uncategorized) {
                $sections[] = ['name' => '未分类', 'images' => $uncategorized];
            }
        } catch (\Throwable $e) {
            // 图库降级为空态是产品决定；但必须留痕，否则故障看起来像「站点没图」。
            error_log('[MoeRNG] 图库渲染失败，已降级为空态：' . $e->getMessage());
        }

        $this->render('gallery', $this->frontData() + [
            'activePage' => 'gallery',
            'pageTitle' => '图库 - ' . Config::get('settings.site_name', 'MoeRNG'),
            'sections' => $sections,
        ]);
    }

    public function about(Request $request): void
    {
        $this->render('about', $this->frontData() + [
            'activePage' => 'about',
            'pageTitle' => '关于 - ' . Config::get('settings.site_name', 'MoeRNG'),
        ]);
    }
}
