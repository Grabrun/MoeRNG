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
            // Degrade gracefully: if we cannot write counters, allow through
            // (fail-open) — a broken limiter must not lock everyone out.
            return '';
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
        if ($dir === '') {
            return [true, $limit, 0];
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        $now = time();
        $data = ['start' => $now, 'count' => 0];
        if (is_file($file)) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (is_array($raw)) {
                $data = $raw;
            }
        }
        if (($now - (int) ($data['start'] ?? $now)) >= $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }
        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return [
            $data['count'] <= $limit,
            max(0, $limit - $data['count']),
            $data['count'] > $limit ? max(1, $windowSeconds - ($now - (int) $data['start'])) : 0,
        ];
    }

    /** True when the bucket has not exceeded its allowance. */
    public static function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        return self::hit($bucket, $limit, $windowSeconds)[0];
    }

    /**
     * Read the current state WITHOUT consuming a token (used by the login
     * form to render the lockout banner — hitting there would self-lock).
     * Same return shape as hit().
     */
    public static function peek(string $bucket, int $limit, int $windowSeconds): array
    {
        $dir = self::dir();
        if ($dir === '') {
            return [true, $limit, 0];
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
        if ($dir === '') {
            return;
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
