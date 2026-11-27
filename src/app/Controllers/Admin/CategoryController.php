<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index(Request $request): void
    {
        $categories = Category::getTree();

        // 一次 GROUP BY 聚合取各类图片数，避免 N+1。
        $counts = [];
        try {
            $rows = \App\Core\Database::getInstance()
                ->query("SELECT category_id, COUNT(*) AS c FROM `images` GROUP BY category_id")
                ->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $counts[(int) $r['category_id']] = (int) $r['c'];
            }
        } catch (\Throwable) {
            // images 表可能尚未创建（全新安装）。
        }

        $this->render('admin/categories', [
            'title' => '分类管理',
            'categories' => $categories,
            'imageCounts' => $counts,
        ]);
    }

    public function create(Request $request): void
    {

        $name = trim((string) $request->input('name', ''));
        $category = new Category([
            'name' => $name,
            'slug' => $request->input('slug', '') ?: $this->slugify($name),
            'description' => $request->input('description', ''),
            'parent_id' => $request->input('parent_id') ? (int) $request->input('parent_id') : null,
            'sort_order' => (int) $request->input('sort_order', '0'),
        ]);

        if ($name === '') {
            $this->fail('分类名称不能为空。', 422, '/admin/categories');
        }

        // slug 有 UNIQUE 约束；PDO 是 ERRMODE_EXCEPTION，冲突会抛异常而非返回 false。
        try {
            $saved = $category->save();
        } catch (\PDOException $e) {
            if ($this->isDuplicateSlug($e)) {
                $this->fail('分类名称（slug）已存在，请换一个名称或直接填写不同的 slug。', 422, '/admin/categories');
            }
            throw $e;
        }
        if (!$saved) {
            $this->fail('分类保存失败，请重试。', 422, '/admin/categories');
        }
        \App\Models\AuditLog::record('category_create', [
            'id'        => (int) $category->id,
            'name'      => (string) $category->name,
            'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
        ]);
        $this->ok('分类创建成功。', [], '/admin/categories');
    }

    public function update(Request $request): void
    {
        $id = (int) $request->input('id');
        $category = Category::find($id);

        if (!$category) {
            $this->fail('分类不存在。', 404, '/admin/categories');
        }

        $category->name = $request->input('name', $category->name);
        $category->slug = $request->input('slug', $category->slug) ?: $this->slugify($category->name);
        $category->description = $request->input('description', $category->description);
        $category->sort_order = (int) $request->input('sort_order', $category->sort_order);

        $parentId = $request->input('parent_id');
        $parentId = $parentId !== '' && $parentId !== 'null' && $parentId !== null ? (int) $parentId : null;
        // 防止把分类挂到自身或后代，否则树遍历成环。
        if ($parentId !== null && $this->wouldCreateCycle($id, $parentId)) {
            $this->fail('不能把分类移动到它自己或它的子分类下。', 422, '/admin/categories');
        }
        $category->parent_id = $parentId;

        try {
            $saved = $category->save();
        } catch (\PDOException $e) {
            if ($this->isDuplicateSlug($e)) {
                $this->fail('分类名称（slug）已存在，请换一个名称或直接填写不同的 slug。', 422, '/admin/categories');
            }
            throw $e;
        }
        if (!$saved) {
            $this->fail('分类保存失败，请重试。', 422, '/admin/categories');
        }
        \App\Models\AuditLog::record('category_update', [
            'id'   => (int) $category->id,
            'name' => (string) $category->name,
        ]);
        $this->ok('分类更新成功。', [], '/admin/categories');
    }

    public function delete(Request $request): void
    {
        $id = (int) $request->input('id');
        $category = Category::find($id);

        if (!$category) {
            $this->fail('分类不存在。', 404, '/admin/categories');
        }

        $deletedName = (string) $category->name;
        try {
            $category->delete();
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422, '/admin/categories');
        }
        \App\Models\AuditLog::record('category_delete', [
            'id'   => $id,
            'name' => $deletedName,
        ]);
        $this->ok('分类已删除。', ['id' => $id], '/admin/categories');
    }

    /** 只认 MySQL 1062（唯一键冲突），其它数据库错误照常上抛。 */
    private function isDuplicateSlug(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    private function wouldCreateCycle(int $id, int $newParentId): bool
    {
        if ($id === $newParentId) return true;

        $cursor = Category::find($newParentId);
        $hops = 0;
        while ($cursor && $hops++ < 50) {
            if ((int) $cursor->id === $id) return true;
            $parentId = $cursor->parent_id;
            if ($parentId === null || (int) $parentId === 0) break;
            $cursor = Category::find((int) $parentId);
        }
        return false;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        return trim($text, '-') ?: 'category';
    }
}
