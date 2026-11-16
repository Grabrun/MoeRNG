<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class Setting extends Model
{
    protected static string $table = 'settings';
    protected static string $primaryKey = 'key';
    // settings.key 是自然主键（VARCHAR），非自增。
    protected static bool $incrementing = false;
    protected static array $fillable = ['key', 'value'];

    public static function allAsKeyValue(): array
    {
        $rows = Database::getInstance()->query("SELECT `key`, `value` FROM settings");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    public static function set(string $key, string $value): void
    {
        // 原子 upsert：并发保存设置也不会互相覆盖。
        $stmt = Database::getInstance()->prepare(
            "INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        $stmt->execute([$key, $value]);
    }

    public static function get(string $key, string $default = ''): string
    {
        $setting = self::find($key);
        return $setting ? $setting->value : $default;
    }
}
