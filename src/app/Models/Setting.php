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

        // 同步内存副本：Application 启动时已把全部设置灌进 Config，不回写会让同一请求读到旧值。
        \App\Core\Config::set('settings.' . $key, $value);
    }

    /** 优先读内存副本（启动时已加载全量），避免热路径每请求重复 SELECT。 */
    public static function get(string $key, string $default = ''): string
    {
        $cached = \App\Core\Config::get('settings.' . $key, null);
        if ($cached !== null) {
            return (string) $cached;
        }
        $setting = self::find($key);
        return $setting ? (string) $setting->value : $default;
    }
}
