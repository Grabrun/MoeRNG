<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';
    /**
     * Whether the primary key is an AUTO_INCREMENT column.
     * Models keyed by a natural/business key (e.g. Setting::$primaryKey = 'key')
     * MUST set this to false, otherwise insert() would clobber the real key
     * with lastInsertId() === "0".
     */
    protected static bool $incrementing = true;
    protected static array $fillable = [];
    protected array $attributes = [];
    /** True once the instance is known to correspond to a persisted row. */
    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /** Build an instance from a DB row and flag it as persisted. */
    protected static function hydrate(array $row): static
    {
        $model = new static($row);
        $model->exists = true;
        return $model;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            // The primary key must always survive hydration, otherwise every
            // model fetched from the DB ends up with id === null (fillable acts
            // as a mass-assignment gate and intentionally omits `id`). Without
            // this, login writes user_id = null and the session can never stick.
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

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function save(): bool
    {
        // Auto-increment tables: presence of the id means the row already exists.
        // Natural-key tables (Setting): the key is set even for brand new rows, so
        // we must rely on the hydration flag instead — otherwise every new setting
        // silently ran an UPDATE that matched zero rows and was never persisted.
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

        // Models with a natural primary key (Setting::$primaryKey === 'key') do not
        // list it in $fillable, so it must be carried into the INSERT explicitly.
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
            // Only auto-increment tables hand back a usable lastInsertId(); for a
            // natural key it returns "0" and would destroy the real identifier.
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
        // Never write the primary key inside SET — it is the WHERE target.
        unset($data[static::$primaryKey]);
        if (empty($data)) return false;

        // NOTE: the separator is ", " and NOT "`, `". Each fragment produced by
        // array_map() is already fully back-quoted ("`name` = ?"); joining them
        // with "`, `" injected stray back-quotes between the pairs, which is a
        // SQL syntax error and was the source of every HTTP 500 raised by a
        // multi-field UPDATE (API key edit, API key disable, settings save...).
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

    // ── SQL injection hardening (CVE-2026-MR-001 / MR-002) ──────────────
    // Every dynamic SQL fragment that reaches these query helpers must pass
    // validation. Today all callers pass hardcoded literals; these guards
    // make it IMPOSSIBLE for a future caller to turn a user-controlled value
    // into injected SQL.

    /** Column/identifier names: strict [A-Za-z_][A-Za-z0-9_]* only. */
    private static function assertIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
        }
        return $name;
    }

    /**
     * ORDER BY clauses: only "col [ASC|DESC][, col [ASC|DESC]...]" is
     * accepted. Column names are validated as identifiers; direction tokens
     * must be exactly ASC or DESC. Anything else (subqueries, comments,
     * stacked statements, functions) is rejected.
     */
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

    /**
     * WHERE fragments are composed by INTERNAL code only (hardcoded strings
     * with ? placeholders; user values always go through bound params). As
     * defense-in-depth, reject comment markers / statement separators /
     * identifier-quoting that a hardcoded fragment has no business using —
     * the same characters an injected payload needs.
     */
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
        // (int) cast is defense-in-depth: LIMIT/OFFSET are interpolated, so
        // never trust the caller to have coerced these to integers.
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
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    public static function query(string $sql, array $params = []): array
    {
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function execute(string $sql, array $params = []): bool
    {
        $stmt = Database::getInstance()->prepare($sql);
        return $stmt->execute($params);
    }
}
