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
        //
        // 窗口与额度一样由 key 覆盖（rate_window 此前存了但不生效，属虚假配置）。
        // 无 key 时保持默认 60s。
        $apiKey = $request->header('x-api-key', '');
        if ($apiKey !== '') {
            $keyModel = ApiKey::findByKey($apiKey);
            if ($keyModel && ($keyModel->status ?? '') === 'active') {
                $maxRequests = max(1, $keyModel->getRateLimit());
                $windowSeconds = max(1, (int) ($keyModel->rate_window ?: 60));
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

        // MR-09: 原子 upsert。UNIQUE KEY (identifier, window_key) 让读-改-写无竞态：
        // 并发请求不再各自 SELECT 0 行然后 INSERT N 条重复计数行。先原子自增，
        // 再回读权威计数判定。窗口内允许的额度与旧实现完全一致：
        // 第 1 次 → after=1 → Remaining=max-1；第 max 次 → after=max → 放行；
        // 第 max+1 次 → after=max+1 → 429。
        try {
            Database::getInstance()
                ->prepare('INSERT INTO rate_limits (identifier, window_key, request_count, expires_at, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1, updated_at = ?')
                ->execute([$identifier, (string) $windowKey, $resetAt, $now, $now, $now]);
        } catch (\Throwable $e) {
            // fail-closed（README/CHANGELOG 记载的既有契约）：计数不可用时无法判定
            // 是否超额，放行等于静默取消限流。DB 故障期恰好是最需要限流的时刻。
            // 返回 503 而非 500，明确表达「服务暂时不可用，请重试」而非代码错误。
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
                // 错误响应统一带 success:false（前端可统一判 `if (!j.success)`）
                'success' => false,
                'error' => 'Rate limit exceeded',
                'message' => "Too many requests. Please retry after {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429);
            // v1.1.0-beta.4: MUST stop here — previously the request fell
            // through to the handler after sending 429.
            return null;
        }

        return $next($request);
    }

    private function getIdentifier(Request $request): string
    {
        // Use API Key if provided, otherwise IP.
        //
        // 修复：原先只要 X-API-Key 头非空就直接用它的 md5 当桶标识，
        // **完全不校验这个 key 是否真实存在且启用**。攻击者每次请求带一个随机的
        // X-API-Key 头，就能拿到一个全新的 rate_limits 行（UNIQUE 约束是
        // (identifier, window_key)，见 schema.sql），计数从 1 重新开始 ——
        // 限流被完全绕过，同时 rate_limits 表无界膨胀（清理只按 expires_at）。
        //
        // 现在只对**已验证有效**的 key 按 key 计桶，否则一律回退 IP。
        // ApiKey::findByKey 走 key_hash 唯一索引查表，是常数开销；
        // 且它同时覆盖了 status !== 'active' 的禁用 key。
        $apiKey = $request->header('x-api-key', '');
        if ($apiKey !== '') {
            $keyModel = ApiKey::findByKey($apiKey);
            if ($keyModel !== null && ($keyModel->status ?? '') === 'active') {
                return 'key:' . md5($apiKey);
            }
        }
        return 'ip:' . md5((string) ($request->ip ?? 'unknown'));
    }

    /**
     * MR-32: cache table existence for the lifetime of the request/process.
     * Previously EVERY API request ran `SELECT 1 FROM rate_limits LIMIT 1` (and,
     * on a fresh DB, the CREATE TABLE) — one extra probe query per request. We
     * now probe (and create) at most once; once confirmed ready, all subsequent
     * requests skip the probe entirely. The flag stays false if creation fails so
     * the next request retries.
     */
    private static bool $rateLimitTableReady = false;

    private function ensureRateLimitTable(): void
    {
        if (self::$rateLimitTableReady) {
            return;
        }
        $pdo = Database::getInstance();
        try {
            $pdo->query('SELECT 1 FROM rate_limits LIMIT 1');
            // 表存在。但**存量库**（本次唯一键改动之前建的表）只有普通索引
            // idx_identifier_window，没有 UNIQUE uq_ident_window —— 此时
            // INSERT ... ON DUPLICATE KEY UPDATE 找不到可冲突的唯一键，会退化为
            // 纯 INSERT，每次请求插一行新计数行，回读永远是 count=1，
            // **限流彻底失效且表无界膨胀**。必须在这里补齐唯一键。
            $this->ensureUniqueCounterIndex($pdo);
            self::$rateLimitTableReady = true;
            return;
        } catch (\Throwable) {
            // Table missing — fall through to create it.
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
            // Leave the flag false → retry the probe/create on the next request.
        }
    }

    /**
     * 为已存在的 rate_limits 表补 UNIQUE uq_ident_window (identifier, window_key)。
     *
     * 必须在这里做：Application 的自迁移列表不含 rate_limits，而 schema.sql 与上面的
     * CREATE TABLE IF NOT EXISTS 都只对**新建**表生效 —— 覆盖部署到已有数据库的站点时
     * 唯一键永远不会出现，upsert 便退化为纯 INSERT，限流失效且表无界膨胀。
     *
     * 顺序不能换：先去重（同 identifier+window_key 只留 expires_at 最大的行），
     * 否则 ADD UNIQUE 在有重复行的库上必然失败。ADD UNIQUE 吞 1061 视为幂等。
     * 全程 best-effort，失败仅 error_log，不让 API 500。
     */
    private function ensureUniqueCounterIndex(\PDO $pdo): void
    {
        try {
            $rows = $pdo->query('SHOW INDEX FROM `rate_limits`')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (($row['Key_name'] ?? '') === 'uq_ident_window'
                    && (int) ($row['Non_unique'] ?? 1) === 0) {
                    return; // 已是唯一键，无需处理。
                }
            }

            // 存量库可能有重复计数行（唯一键缺失期间每次请求都插一行）。
            // 先去重：同一 (identifier, window_key) 只保留 expires_at 最大的行，
            // 其余删除，避免 ADD UNIQUE 失败。
            $pdo->exec(
                'DELETE t1 FROM `rate_limits` t1 '
                . 'INNER JOIN `rate_limits` t2 '
                . 'ON t1.`identifier` = t2.`identifier` '
                . 'AND t1.`window_key` = t2.`window_key` '
                . 'AND t1.`expires_at` < t2.`expires_at`'
            );

            $pdo->exec('ALTER TABLE `rate_limits` ADD UNIQUE KEY `uq_ident_window` (`identifier`, `window_key`)');
        } catch (\Throwable $e) {
            // 1061 = 键已存在（幂等成功）；1062/重复行等其它失败仅记录，
            // 不影响 API 可用性 —— 限流会退化为旧语义，但不抛错中断请求。
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code !== 1061) {
                error_log('[MoeRNG] rate_limits 唯一键补齐失败（限流可能退化为弱语义）: ' . $e->getMessage());
            }
        }
    }
}
