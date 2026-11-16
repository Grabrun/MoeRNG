<?php
declare(strict_types=1);

namespace App\Core;

/** 每日计数统计（API 调用 / 站点访问），每指标每天一行 upsert。 */
class Stats
{
    public const TABLE_API    = 'api_stats';
    public const TABLE_VISITS = 'visit_stats';

    public static function bump(string $table): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "INSERT INTO `{$table}` (`day`, `count`) VALUES (CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE `count` = `count` + 1"
            );
            $stmt->execute();
        } catch (\Throwable) {
        }
    }

    /** 失败计数：仅 5xx 计入，与 bump() 的成功数合算 SLA 成功率。 */
    public static function bumpFail(string $table): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "INSERT INTO `{$table}` (`day`, `count`, `fail`) VALUES (CURDATE(), 0, 1)
                 ON DUPLICATE KEY UPDATE `fail` = `fail` + 1"
            );
            $stmt->execute();
        } catch (\Throwable) {
        }
    }

    /** 近 N 天成功率：4xx 算可用，5xx 记入 fail；无样本返回 null（前台显示默认宣传值）。 */
    public static function availability(int $days = 7): ?float
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT COALESCE(SUM(`count`),0) AS total, COALESCE(SUM(`fail`),0) AS fail
                 FROM `api_stats` WHERE `day` >= DATE_SUB(CURDATE(), INTERVAL " . (int) $days . " DAY)"
            );
            $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        } catch (\Throwable) {
            return null;
        }
        $total = (int) ($row['total'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        $fail = (int) ($row['fail'] ?? 0);
        return max(0.0, min(1.0, ($total - $fail) / $total));
    }

    /** 返回 ['total','today','week']。 */
    public static function summary(string $table): array
    {
        try {
            $db = Database::getInstance();
            $total = (int) $db->query("SELECT COALESCE(SUM(`count`),0) FROM `{$table}`")->fetchColumn();
            $today = (int) $db->query("SELECT COALESCE(SUM(`count`),0) FROM `{$table}` WHERE `day` = CURDATE()")->fetchColumn();
            $week  = (int) $db->query("SELECT COALESCE(SUM(`count`),0) FROM `{$table}` WHERE `day` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
        } catch (\Throwable) {
            return ['total' => 0, 'today' => 0, 'week' => 0];
        }
        return ['total' => $total, 'today' => $today, 'week' => $week];
    }

    /** 近 7 天序列 [['day' => 'm-d', 'count' => int], ...]。 */
    public static function series(string $table): array
    {
        $out = [];
        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT `day`, `count` FROM `{$table}`
                 WHERE `day` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY `day` ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $byDay = [];
            foreach ($rows as $row) {
                $byDay[$row['day']] = (int) $row['count'];
            }
        } catch (\Throwable) {
            $byDay = [];
        }
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['day' => date('m-d', strtotime($day)), 'count' => $byDay[$day] ?? 0];
        }
        return $out;
    }
}
