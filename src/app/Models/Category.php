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
     * Fetch direct children of a node.
     *
     * `categories`.`parent_id` is NULL for top-level rows (the FK uses
     * ON DELETE SET NULL), so "parent_id = 0" never matched anything. Root is
     * therefore addressed with $parentId === null / 0 mapped to `IS NULL`.
     *
     * @return array<int, array<string, mixed>|self>
     */
    private static function childrenRowsOf(?int $parentId, bool $asModels = false): array
    {
        $isRoot = ($parentId === null || $parentId === 0);
        $sql = "SELECT * FROM `categories` WHERE "
            . ($isRoot ? "(`parent_id` IS NULL OR `parent_id` = 0)" : "`parent_id` = ?")
            . " ORDER BY `sort_order` ASC, `id` ASC";

        // PDOStatement::execute() returns bool — it must NOT be chained into
        // fetchAll(). Doing so raised "Call to a member function fetchAll() on
        // bool" and was the direct cause of the HTTP 500 on /admin/categories.
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($isRoot ? [] : [$parentId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $asModels ? array_map(fn($row) => self::hydrate($row), $rows) : $rows;
    }

    public static function getTree(?int $parentId = null, int $depth = 0): array
    {
        // Hard stop against a self-referencing parent_id cycle in dirty data.
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

    /** Hard stop for recursive subtree deletion — mirrors getTree()/getFlatTree(). */
    private const MAX_DELETE_DEPTH = 20;

    public function delete(): bool
    {
        return $this->deleteSubtree(0, true);
    }

    /**
     * MR-16: recursive subtree delete with a depth guard and a single transaction.
     *
     * - Depth guard: a self-referencing parent_id cycle in dirty data would
     *   otherwise recurse forever and blow the PHP stack (getTree()/getFlatTree()
     *   already bail at 20; the delete path was the one that was missing it).
     * - Transaction: the child deletes + the images unbind + this row delete run
     *   atomically, so a mid-way DB failure rolls back instead of leaving a
     *   half-deleted tree / partially-unbound images.
     *
     * @param int  $depth     recursion depth (internal).
     * @param bool $outermost true only on the top-level call — it owns BEGIN/COMMIT.
     * @throws \RuntimeException when the depth guard trips (dirty parent_id cycle).
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
            // Delete children first (recursion; inner calls do NOT open a new txn).
            foreach ($this->children() as $child) {
                $child->deleteSubtree($depth + 1, false);
            }
            // Update images in this category to uncategorized.
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
