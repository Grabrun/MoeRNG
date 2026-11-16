<?php
declare(strict_types=1);

namespace App\Core;

/** 文件型滑动窗口限流，仅用于登录失败锁定（每 IP 一个 login:<ip> 桶）。 */
class RateLimiter
{
    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/var/rate-limit';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // 目录不可用时失败关闭：宁可全部拒绝，也不能让登录锁定静默失效。
            @error_log('[MoeRNG] Rate limiter directory unavailable: ' . $dir);
            return "\x00FAIL";
        }
        return $dir;
    }

    /** @return array{0:bool,1:int,2:int} [allowed, remaining, retryAfterSec] */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $dir = self::dir();
        if ($dir === "\x00FAIL") {
            return [false, 0, 0];
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        $now = time();

        // 整个读-改-写必须持有同一把排他锁，否则并发登录会丢失更新、锁定阈值被推迟。
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return [false, 0, 0];
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return [false, 0, 0];
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

    /** 只读当前状态不消耗 token（渲染锁定横幅用，否则会自我锁定）；返回结构同 hit()。 */
    public static function peek(string $bucket, int $limit, int $windowSeconds): array
    {
        $dir = self::dir();
        if ($dir === "\x00FAIL") {
            return [false, 0, 0];
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

    public static function reset(string $bucket): void
    {
        $dir = self::dir();
        if ($dir === "\x00FAIL") {
            return;
        }
        $file = $dir . '/' . md5($bucket) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
