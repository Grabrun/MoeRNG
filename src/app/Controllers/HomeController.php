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
    /**
     * v1.3.1 迭代: 前台由单页拆分为多页导航（/ /docs /tester /about）。
     * 每页共享同一组站点数据，只有首屏统计只在首页计算。
     */
    private function frontData(): array
    {
        return [
            'siteName' => Config::get('settings.site_name', 'MoeRNG'),
            'siteSlogan' => Config::get('settings.site_slogan', '随机二次元图片 API 服务'),
            'logoUrl' => Config::get('settings.logo_url', '') ?: '/assets/logo.png',
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
            'categories' => $this->safeCategories(),
        ];
    }

    /** Category list that never breaks the page when the DB is unavailable. */
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
        // v1.2.0 迭代: site visit counter (best effort, never breaks the page).
        \App\Core\Stats::bump(\App\Core\Stats::TABLE_VISITS);

        $data = $this->frontData();
        // v1.2.1 修复: 移除文件缓存——宝塔 open_basedir（防跨站）会拦截项目根
        // var/ 路径的文件读写，首页直接报「跨目录读取已被拦截」。COUNT 查询
        // 走索引很快，直接查询即可，不引入文件系统依赖。
        $data['totalImages'] = 0;
        $data['totalCategories'] = 0;
        try {
            $data['totalImages'] = Image::count("status = 'active'");
            $data['totalCategories'] = Category::count();
        } catch (\Throwable) {
            // DB may not be available
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

    /**
     * v1.3.1 图库页（v2 改版）: 按分类分区展示，每个分类随机 12 张，
     * 不再做全量分页列表。未分类图片单独一个区块放在最后；空分类不显示。
     */
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
            // 未分类图片收尾区块
            $uncategorized = Image::randomBatch(null, 12);
            if ($uncategorized) {
                $sections[] = ['name' => '未分类', 'images' => $uncategorized];
            }
        } catch (\Throwable) {
            // DB unavailable — empty gallery instead of an error page
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
