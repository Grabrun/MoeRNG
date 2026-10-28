<?php
declare(strict_types=1);

namespace App\Core;

/**
 * File-backed sliding-window rate limiter (v1.1.0-beta.4).
 *
 * Deliberately zero-dependency: writes a tiny JSON counter per bucket under
 * <root>/var/rate-limit/. Good enough for a single-application deploy and far
 * simpler to reason about than Redis when the extension is not guaranteed.
 *
 * Buckets are keyed by caller-provided strings (e.g. "login:1.2.3.4" or
 * "api:1.2.3.4"), so the same primitive serves both login lockout and the
 * public API rate limit.
 */
class RateLimiter
{
    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/var/rate-limit';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // v1.2.1 security (audit V-02): fail-CLOSED — an unusable limiter
            // must deny (login lockout stays effective) instead of silently
            // allowing every request through. Log loudly for ops.
            @error_log('[MoeRNG] Rate limiter directory unavailable: ' . $dir);
            return "\x00FAIL";
        }
        return $dir;
    }

    /**
     * Consume one token in a sliding window.
     *
     * @return array{0:bool,1:int,2:int} [allowed, remaining, retryAfterSec]
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $dir = self::dir();
        if ($dir === "\x00FAIL") {
            return [false, 0, 0]; // fail-closed (audit V-02)
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        $now = time();

        // MR-10: the ENTIRE read-modify-write must happen under one exclusive
        // lock. Previously the file was read unlocked, then only the write used
        // LOCK_EX — concurrent login attempts each read count=N, +1, and write
        // back (last-write-wins → lost updates → lockout threshold delayed).
        // Open c+ (read+write, create-if-missing, no truncate) and hold LOCK_EX
        // until the new state is fully flushed.
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return [false, 0, 0]; // cannot open/create bucket → fail closed
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return [false, 0, 0]; // cannot lock → fail closed
        }

        $data = ['start' => $now, 'count' => 0];
        $raw = stream_get_contents($fh);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        if (($now - (int) ($data['start'] ?? $now)) >= $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }
        $data['count'] = (int) ($data['count'] ?? 0) + 1;

        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        return [
            $data['count'] <= $limit,
            max(0, $limit - $data['count']),
            $data['count'] > $limit ? max(1, $windowSeconds - ($now - (int) $data['start'])) : 0,
        ];
    }

    /**
     * Read the current state WITHOUT consuming a token (used by the login
     * form to render the lockout banner — hitting there would self-lock).
     * Same return shape as hit().
     */
    public static function peek(string $bucket, int $limit, int $windowSeconds): array
    {
        $dir = self::dir();
        if ($dir === "\x00FAIL") {
            return [false, 0, 0]; // fail-closed (audit V-02)
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        $now = time();
        $count = 0;
        $start = $now;
        if (is_file($file)) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (is_array($raw)) {
                $start = (int) ($raw['start'] ?? $now);
                $count = (int) ($raw['count'] ?? 0);
            }
        }
        if (($now - $start) >= $windowSeconds) {
            return [true, $limit, 0];
        }
        return [
            $count <= $limit,
            max(0, $limit - $count),
            $count > $limit ? max(1, $windowSeconds - ($now - $start)) : 0,
        ];
    }

    /** Drop a bucket (e.g. reset login attempts after a successful login). */
    public static function reset(string $bucket): void
    {
        $dir = self::dir();
        if ($dir === '' || $dir === "\x00FAIL") {
            return;
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
