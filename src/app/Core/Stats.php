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

    /**
     * Increment today's failure counter for the given table (best effort).
     *
     * v2.0.0-beta.2: 服务可用性实装 —— 5xx（未捕获异常）走这里单独记账，
     * 与 bump() 的成功计数合算 SLA 成功率。4xx 是业务校验失败、服务本身
     * 正常响应，不算"不可用"，因此仍走 bump()。
     */
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
            // stats must never break the request — silent best effort.
        }
    }

    /**
     * API 可用性（SLA 口径）：近 N 天 成功数 / 总请求数。
     *
     * - 分母 = 进入路由且正常返回的请求（含 4xx —— 服务响应了即"可用"）；
     * - 分子 = 其中未 5xx 的请求（total - fail）；
     * - 无样本（新装 / 尚无流量）返回 null，由前台显示默认宣传值 99.9%。
     */
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
