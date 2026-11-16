<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class Category extends Model
{
    protected static string $table = 'categories';
    protected static array $fillable = ['name', 'slug', 'description', 'parent_id', 'sort_order', 'status'];

    public function images()
    {
        return Image::where('category_id', $this->id);
    }

    public function children(): array
    {
        return self::childrenRowsOf((int) $this->id, true);
    }

    /**
     * parent_id 为 NULL 表示顶层（FK 用 ON DELETE SET NULL），故 0 也映射到 IS NULL。
     *
     * @return array<int, array<string, mixed>|self>
     */
    private static function childrenRowsOf(?int $parentId, bool $asModels = false): array
    {
        $isRoot = ($parentId === null || $parentId === 0);
        $sql = "SELECT * FROM `categories` WHERE "
            . ($isRoot ? "(`parent_id` IS NULL OR `parent_id` = 0)" : "`parent_id` = ?")
            . " ORDER BY `sort_order` ASC, `id` ASC";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($isRoot ? [] : [$parentId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $asModels ? array_map(fn($row) => self::hydrate($row), $rows) : $rows;
    }

    public static function getTree(?int $parentId = null, int $depth = 0): array
    {
        // 脏数据里 parent_id 自引用环的硬停。
        if ($depth > 20) return [];

        $tree = [];
        foreach (self::childrenRowsOf($parentId) as $cat) {
            $node = $cat;
            $node['children'] = self::getTree((int) $cat['id'], $depth + 1);
            $tree[] = $node;
        }
        return $tree;
    }

    public static function getFlatTree(?int $parentId = null, int $depth = 0): array
    {
        if ($depth > 20) return [];

        $result = [];
        foreach (self::childrenRowsOf($parentId, true) as $cat) {
            $cat->depth = $depth;
            $cat->prefix = str_repeat('-- ', $depth);
            $result[] = $cat;
            $result = array_merge($result, self::getFlatTree((int) $cat->id, $depth + 1));
        }
        return $result;
    }

    public static function getBySlug(string $slug): ?self
    {
        return self::firstWhere('slug', $slug);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    private const MAX_DELETE_DEPTH = 20;

    public function delete(): bool
    {
        return $this->deleteSubtree(0, true);
    }

    /**
     * 子树递归删除，整体包在一个事务里（中途失败回滚，不留半删状态）。
     *
     * @throws \RuntimeException 深度超限（多为 parent_id 环）。
     */
    private function deleteSubtree(int $depth, bool $outermost): bool
    {
        if ($depth > self::MAX_DELETE_DEPTH) {
            throw new \RuntimeException(
                '分类层级过深（超过 ' . self::MAX_DELETE_DEPTH . ' 层），或存在 parent_id 环；已中止删除以保护数据。'
            );
        }
        $db = Database::getInstance();
        if ($outermost) {
            $db->beginTransaction();
        }
        try {
            foreach ($this->children() as $child) {
                $child->deleteSubtree($depth + 1, false);
            }
            $stmt = $db->prepare("UPDATE `images` SET `category_id` = NULL WHERE `category_id` = ?");
            $stmt->execute([$this->id]);
            $result = parent::delete();
            if ($outermost) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($outermost && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
