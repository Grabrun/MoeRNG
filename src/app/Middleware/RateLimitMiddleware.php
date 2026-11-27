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
        $maxRequests = max(1, (int) (Setting::get('api_rate_limit_per_minute', '100') ?: 100));
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
            error_log('[MoeRNG] 限流计数写入失败，fail-closed 拒绝请求：' . $e->getMessage());
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
        try {
            $pdo = Database::getInstance();
            $err = \App\Core\Application::ensureRateLimitTable($pdo);
            // 唯一键补齐失败时不重复尝试（每请求做一次去重 DELETE 比退化更糟）；表本身在就继续。
            self::$rateLimitTableReady = $err === '' || $pdo->query('SELECT 1 FROM rate_limits LIMIT 1') !== false;
        } catch (\Throwable) {
        }
    }
}
