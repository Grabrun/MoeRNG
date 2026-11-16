<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';
    // 自然主键模型必须设为 false，否则 insert 会用 lastInsertId()（"0"）覆盖真主键。
    protected static bool $incrementing = true;
    protected static array $fillable = [];
    protected array $attributes = [];
    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    protected static function hydrate(array $row): static
    {
        $model = new static($row);
        $model->exists = true;
        return $model;
    }

    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            // 主键永远放行：$fillable 不含 id，否则所有 hydrated 行的 id 都是 null。
            if ($key === static::$primaryKey || in_array($key, static::$fillable, true)) {
                $this->attributes[$key] = $value;
            }
        }
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function save(): bool
    {
        // 自然主键表新行的 key 已存在，只能靠 exists 标记区分插入/更新。
        $shouldUpdate = static::$incrementing
            ? isset($this->attributes[static::$primaryKey])
            : $this->exists;

        if ($shouldUpdate) {
            return $this->update();
        }

        $ok = $this->insert();
        if ($ok) $this->exists = true;
        return $ok;
    }

    protected function insert(): bool
    {
        $data = array_intersect_key($this->attributes, array_flip(static::$fillable));

        // 自然主键不在 $fillable 里，需显式带进 INSERT。
        if (!static::$incrementing
            && isset($this->attributes[static::$primaryKey])
            && !array_key_exists(static::$primaryKey, $data)) {
            $data = [static::$primaryKey => $this->attributes[static::$primaryKey]] + $data;
        }

        if (empty($data)) return false;

        $columns = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `" . static::$table . "` ({$columns}) VALUES ({$placeholders})";
        $stmt = Database::getInstance()->prepare($sql);

        if ($stmt->execute(array_values($data))) {
            // lastInsertId 只对自增表有效，自然键会返回 "0"。
            if (static::$incrementing) {
                $this->attributes[static::$primaryKey] = (int) Database::getInstance()->lastInsertId();
            }
            return true;
        }
        return false;
    }

    protected function update(): bool
    {
        $data = array_intersect_key($this->attributes, array_flip(static::$fillable));
        unset($data[static::$primaryKey]);
        if (empty($data)) return false;

        $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $sql = "UPDATE `" . static::$table . "` SET {$sets} WHERE `" . static::$primaryKey . "` = ?";

        $values = array_values($data);
        $values[] = $this->attributes[static::$primaryKey];

        $stmt = Database::getInstance()->prepare($sql);
        return $stmt->execute($values);
    }

    public function delete(): bool
    {
        if (!isset($this->attributes[static::$primaryKey])) return false;
        $sql = "DELETE FROM `" . static::$table . "` WHERE `" . static::$primaryKey . "` = ?";
        $stmt = Database::getInstance()->prepare($sql);
        return $stmt->execute([$this->attributes[static::$primaryKey]]);
    }

    public static function find(int|string $id): ?static
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE `" . static::$primaryKey . "` = ? LIMIT 1";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? static::hydrate($row) : null;
    }

    // 动态 SQL 片段一律过校验：调用方无法把外部输入变成注入。
    private static function assertIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
        }
        return $name;
    }

    private static function assertOrderBy(string $orderBy): string
    {
        $orderBy = trim($orderBy);
        if ($orderBy === '') {
            return '';
        }
        foreach (explode(',', $orderBy) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            if (count($tokens) < 1 || count($tokens) > 2) {
                throw new \InvalidArgumentException("Invalid ORDER BY fragment: {$part}");
            }
            self::assertIdentifier($tokens[0]);
            if (isset($tokens[1]) && !preg_match('/^(ASC|DESC)$/i', $tokens[1])) {
                throw new \InvalidArgumentException("Invalid ORDER BY direction: {$tokens[1]}");
            }
        }
        return $orderBy;
    }

    // WHERE 只由内部拼装；这里拒绝注释符、语句分隔符与反引号——注入载荷所需的字符。
    private static function assertWhereFragment(string $where): string
    {
        if (preg_match('/[;`]|--|\/\*|\*\//', $where)) {
            throw new \InvalidArgumentException('Suspicious WHERE fragment rejected');
        }
        return $where;
    }

    public static function all(string $orderBy = ''): array
    {
        $orderBy = self::assertOrderBy($orderBy);
        $sql = "SELECT * FROM `" . static::$table . "`";
        if ($orderBy) $sql .= " ORDER BY {$orderBy}";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute();
        return array_map(fn($row) => static::hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function where(string $column, mixed $value): array
    {
        $column = self::assertIdentifier($column);
        $sql = "SELECT * FROM `" . static::$table . "` WHERE `{$column}` = ?";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$value]);
        return array_map(fn($row) => static::hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function firstWhere(string $column, mixed $value): ?static
    {
        $column = self::assertIdentifier($column);
        $sql = "SELECT * FROM `" . static::$table . "` WHERE `{$column}` = ? LIMIT 1";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? static::hydrate($row) : null;
    }

    public static function count(string $where = '', array $params = []): int
    {
        $where = self::assertWhereFragment($where);
        $sql = "SELECT COUNT(*) FROM `" . static::$table . "`";
        if ($where) $sql .= " WHERE {$where}";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function paginate(int $page = 1, int $perPage = 20, string $orderBy = '', string $where = '', array $params = []): array
    {
        // LIMIT/OFFSET 直接插值，必须强制转 int。
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $orderBy = self::assertOrderBy($orderBy);
        $where = self::assertWhereFragment($where);
        $total = static::count($where, $params);
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM `" . static::$table . "`";
        if ($where) $sql .= " WHERE {$where}";
        if ($orderBy) $sql .= " ORDER BY {$orderBy}";
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $items = array_map(fn($row) => static::hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
