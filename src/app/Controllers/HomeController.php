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

    /** 图库每栏张数：电脑端、移动端各 6 张。 */
    private const GALLERY_PER_SIDE = 6;

    /**
     * 按分类取两组图（电脑端横图 / 移动端竖图）。分类没有图时整段不出现。
     *
     * @return array<int, array{name: string, pc: array, pe: array}>
     */
    private function gallerySections(): array
    {
        $sections = [];
        foreach ($this->safeCategories() as $cat) {
            $batch = Image::galleryBatch((int) $cat->id, self::GALLERY_PER_SIDE);
            if ($batch['pc'] || $batch['pe']) {
                $sections[] = ['name' => (string) $cat->name, 'pc' => $batch['pc'], 'pe' => $batch['pe']];
            }
        }

        $batch = Image::galleryBatch(null, self::GALLERY_PER_SIDE);
        if ($batch['pc'] || $batch['pe']) {
            $sections[] = ['name' => '未分类', 'pc' => $batch['pc'], 'pe' => $batch['pe']];
        }

        return $sections;
    }

    public function gallery(Request $request): void
    {
        $sections = [];
        try {
            $sections = $this->gallerySections();
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

    /** 「换一批」：返回与首屏同一份模板渲染的片段，避免 PHP / JS 两套卡片模板漂移。 */
    public function galleryFeed(Request $request): void
    {
        try {
            $sections = $this->gallerySections();
        } catch (\Throwable $e) {
            error_log('[MoeRNG] 图库刷新失败：' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo '图库刷新失败，请稍后再试。';
            return;
        }

        require_once dirname(__DIR__) . '/helpers.php';
        $data = ['sections' => $sections];
        extract($data);
        ob_start();
        require dirname(__DIR__, 2) . '/views/partials/gallery_sections.php';
        $fragment = (string) ob_get_clean();

        header('Cache-Control: no-store');
        (new \App\Core\Response())->html($fragment);
    }

    public function about(Request $request): void
    {
        $this->render('about', $this->frontData() + [
            'activePage' => 'about',
            'pageTitle' => '关于 - ' . Config::get('settings.site_name', 'MoeRNG'),
        ]);
    }
}
