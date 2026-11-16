<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\Setting;
use App\Models\ApiKey;

class RateLimitMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        if (Setting::get('api_rate_limit_enabled', '0') !== '1') {
            return $next($request);
        }

        // 只对已验证有效的 key 分桶：否则随机 X-API-Key 每次都能拿到新计数行，限流被绕过且计数表无界膨胀。
        $apiKey = (string) $request->header('x-api-key', '');
        $keyModel = $apiKey !== '' ? ApiKey::findByKey($apiKey) : null;
        $validKey = $keyModel !== null && ($keyModel->status ?? '') === 'active';

        $identifier = $validKey
            ? 'key:' . md5($apiKey)
            : 'ip:' . md5((string) ($request->ip ?? 'unknown'));

        $windowSeconds = 60;
        $maxRequests = max(1, (int) (Setting::get('api_rate_limit_per_minute', '60') ?: 60));
        if ($validKey) {
            $maxRequests = max(1, $keyModel->getRateLimit());
            $windowSeconds = max(1, (int) ($keyModel->rate_window ?: 60));
        }

        $now = time();
        $windowKey = floor($now / $windowSeconds);
        $resetAt = ($windowKey + 1) * $windowSeconds;

        $this->ensureRateLimitTable();

        try {
            Database::getInstance()
                ->prepare('DELETE FROM rate_limits WHERE expires_at < ?')
                ->execute([$now]);
        } catch (\Throwable) {
        }

        // 原子 upsert：并发请求不会各自 SELECT 0 行后插入重复计数行。
        try {
            Database::getInstance()
                ->prepare('INSERT INTO rate_limits (identifier, window_key, request_count, expires_at, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1, updated_at = ?')
                ->execute([$identifier, (string) $windowKey, $resetAt, $now, $now, $now]);
        } catch (\Throwable $e) {
            // 计数不可用时必须 fail-closed，否则 DB 故障期等于静默取消限流。
            error_log('[MoeRNG] 限流计数写入失败，fail-closed 拒绝请求: ' . $e->getMessage());
            $response = new Response();
            $response->json([
                'success' => false,
                'error'   => 'Service Unavailable',
                'message' => 'Rate limiter unavailable. Please retry shortly.',
            ], 503);
            return null;
        }

        $stmt = Database::getInstance()
            ->prepare('SELECT request_count FROM rate_limits WHERE identifier = ? AND window_key = ?');
        $stmt->execute([$identifier, (string) $windowKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $afterCount = $row ? (int) $row['request_count'] : 1;

        header("X-RateLimit-Limit: {$maxRequests}");
        header("X-RateLimit-Remaining: " . max(0, $maxRequests - $afterCount));
        header("X-RateLimit-Reset: {$resetAt}");

        if ($afterCount > $maxRequests) {
            $retryAfter = $resetAt - $now;
            header("Retry-After: {$retryAfter}");
            $response = new Response();
            $response->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'message' => "Too many requests. Please retry after {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429);
            return null;
        }

        return $next($request);
    }

    private static bool $rateLimitTableReady = false;

    private function ensureRateLimitTable(): void
    {
        if (self::$rateLimitTableReady) {
            return;
        }
        $pdo = Database::getInstance();
        try {
            $pdo->query('SELECT 1 FROM rate_limits LIMIT 1');
            // 存量表可能只有普通索引，缺唯一键会让 upsert 退化为纯 INSERT（限流失效、表膨胀）。
            $this->ensureUniqueCounterIndex($pdo);
            self::$rateLimitTableReady = true;
            return;
        } catch (\Throwable) {
        }
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(64) NOT NULL,
                    window_key VARCHAR(64) NOT NULL,
                    request_count INT NOT NULL DEFAULT 0,
                    expires_at INT NOT NULL,
                    created_at INT NOT NULL,
                    updated_at INT NOT NULL,
                    UNIQUE KEY uq_ident_window (identifier, window_key),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$rateLimitTableReady = true;
        } catch (\Throwable) {
        }
    }

    /** 为存量表补 UNIQUE 键：先去重再加键，否则 ADD UNIQUE 必然失败。 */
    private function ensureUniqueCounterIndex(\PDO $pdo): void
    {
        try {
            $rows = $pdo->query('SHOW INDEX FROM `rate_limits`')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (($row['Key_name'] ?? '') === 'uq_ident_window'
                    && (int) ($row['Non_unique'] ?? 1) === 0) {
                    return;
                }
            }

            $pdo->exec(
                'DELETE t1 FROM `rate_limits` t1 '
                . 'INNER JOIN `rate_limits` t2 '
                . 'ON t1.`identifier` = t2.`identifier` '
                . 'AND t1.`window_key` = t2.`window_key` '
                . 'AND t1.`expires_at` < t2.`expires_at`'
            );

            $pdo->exec('ALTER TABLE `rate_limits` ADD UNIQUE KEY `uq_ident_window` (`identifier`, `window_key`)');
        } catch (\Throwable $e) {
            // 1061 = 键已存在（幂等）；其它失败仅记录，不中断请求。
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code !== 1061) {
                error_log('[MoeRNG] rate_limits 唯一键补齐失败（限流可能退化为弱语义）: ' . $e->getMessage());
            }
        }
    }
}
