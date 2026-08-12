<?php
declare(strict_types=1);

namespace App\Core;

/**
 * v1.2.0 迭代: daily counter stats (API calls / site visits).
 *
 * One row per day per metric, upserted with INSERT..ON DUPLICATE KEY UPDATE —
 * a single indexed write per request, cheap enough for hot paths. The tables
 * are created by Application::ensureStatsTables() during the runtime migration.
 */
class Stats
{
    public const TABLE_API    = 'api_stats';
    public const TABLE_VISITS = 'visit_stats';

    /** Increment today's counter for the given table (best effort). */
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
            // stats must never break the request — silent best effort.
        }
    }

    /** Aggregate: [total, today, last7days] for the given table. */
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

    /** Last 7 days series [['day' => 'm-d', 'count' => int], ...] for charts. */
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
