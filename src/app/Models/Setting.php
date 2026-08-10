<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

class Setting extends Model
{
    protected static string $table = 'settings';
    protected static string $primaryKey = 'key';
    /** `settings`.`key` is a natural VARCHAR primary key, not AUTO_INCREMENT. */
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
        // Atomic upsert: avoids the insert-vs-update guessing game entirely and
        // is race-safe when two admin tabs save settings at the same time.
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
