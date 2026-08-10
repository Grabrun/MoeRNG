<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\Setting;
use App\Models\ApiKey;

/**
 * API rate limiting (v1.1.0-beta.4: settings-driven).
 *
 * - Disabled entirely when settings.api_rate_limit_enabled is off.
 * - Default window limit comes from settings.api_rate_limit_per_minute.
 * - A valid active API key overrides the per-key limit (ApiKey::getRateLimit).
 * - Uses the DB-backed rate_limits table (window_key = minute bucket), and
 *   returns HTTP 429 with Retry-After when exceeded.
 */
class RateLimitMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        // Master switch from the settings store.
        if (Setting::get('api_rate_limit_enabled', '0') !== '1') {
            return $next($request);
        }

        $identifier = $this->getIdentifier($request);
        $windowSeconds = 60;
        $maxRequests = max(1, (int) (Setting::get('api_rate_limit_per_minute', '60') ?: 60));

        // A valid active API key overrides the per-key limit.
        $apiKey = $request->header('x-api-key', '');
        if ($apiKey !== '') {
            $keyModel = ApiKey::findByKey($apiKey);
            if ($keyModel && ($keyModel->status ?? '') === 'active') {
                $maxRequests = max(1, $keyModel->getRateLimit());
            }
        }

        $now = time();
        $windowKey = floor($now / $windowSeconds);
        $resetAt = ($windowKey + 1) * $windowSeconds;

        $this->ensureRateLimitTable();

        // Clean expired rows opportunistically.
        try {
            Database::getInstance()
                ->prepare('DELETE FROM rate_limits WHERE expires_at < ?')
                ->execute([$now]);
        } catch (\Throwable) {
            // best effort
        }

        $stmt = Database::getInstance()
            ->prepare('SELECT request_count FROM rate_limits WHERE identifier = ? AND window_key = ?');
        $stmt->execute([$identifier, (string) $windowKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $currentCount = $row ? (int) $row['request_count'] : 0;

        header("X-RateLimit-Limit: {$maxRequests}");
        header("X-RateLimit-Remaining: " . max(0, $maxRequests - $currentCount - 1));
        header("X-RateLimit-Reset: {$resetAt}");

        if ($currentCount >= $maxRequests) {
            $retryAfter = $resetAt - $now;
            header("Retry-After: {$retryAfter}");
            $response = new Response();
            $response->json([
                'error' => 'Rate limit exceeded',
                'message' => "Too many requests. Please retry after {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429);
            // v1.1.0-beta.4: MUST stop here — previously the request fell
            // through to the handler after sending 429.
            return null;
        }

        // Increment counter.
        if ($row) {
            Database::getInstance()
                ->prepare('UPDATE rate_limits SET request_count = request_count + 1, updated_at = ? WHERE identifier = ? AND window_key = ?')
                ->execute([$now, $identifier, (string) $windowKey]);
        } else {
            Database::getInstance()
                ->prepare('INSERT INTO rate_limits (identifier, window_key, request_count, expires_at, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?)')
                ->execute([$identifier, (string) $windowKey, $resetAt, $now, $now]);
        }

        return $next($request);
    }

    private function getIdentifier(Request $request): string
    {
        // Use API Key if provided, otherwise IP.
        $apiKey = $request->header('x-api-key', '');
        if ($apiKey !== '') {
            return 'key:' . md5($apiKey);
        }
        return 'ip:' . md5((string) ($request->ip ?? 'unknown'));
    }

    private function ensureRateLimitTable(): void
    {
        try {
            Database::getInstance()->query('SELECT 1 FROM rate_limits LIMIT 1');
        } catch (\Throwable) {
            Database::getInstance()->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(64) NOT NULL,
                    window_key VARCHAR(64) NOT NULL,
                    request_count INT NOT NULL DEFAULT 0,
                    expires_at INT NOT NULL,
                    created_at INT NOT NULL,
                    updated_at INT NOT NULL,
                    INDEX idx_identifier_window (identifier, window_key),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
}
