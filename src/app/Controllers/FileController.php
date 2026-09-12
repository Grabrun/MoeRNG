<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Storage\LocalDriver;
use App\Storage\SignedUrl;

/**
 * v1.2.0 迭代: signed download endpoint for LOCAL storage.
 *
 * 本地文件不再通过固定静态链接暴露，改为短时签名链接：
 *   /files?p={base64url(path)}&e={expires}&s={hmac}
 * 校验通过后流式输出文件（含 Content-Type；Cache-Control: private）。
 */
class FileController extends Controller
{
    public function show(Request $request): void
    {
        $encoded = (string) $request->input('p', '');
        $expires = (int) $request->input('e', '0');
        $sig = (string) $request->input('s', '');

        if ($encoded === '' || $expires <= 0 || $sig === '') {
            $this->abort(400);
        }
        $path = SignedUrl::decodePath($encoded);
        if ($path === '' || !SignedUrl::verify($path, $expires, $sig)) {
            $this->abort(410); // Gone / expired link
        }

        // v1.5.0-beta.1: 按优先级在候选根中解析，任一命中即可 —— 迁根期间、多实例
        // 或自定义路径都不会 404。**先试零查询的默认根/历史根**（覆盖绝大多数
        // 请求），只有都没命中才去查存储实例表；此前每张图都要查 2 条实例查询。
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $real = self::locateIn(
            [LocalDriver::defaultUploadDir(), LocalDriver::legacyUploadDir()],
            $relative
        );
        if ($real === false) {
            $real = self::locateIn(self::profileRoots(), $relative);
        }

        if ($real === false) {
            $this->abort(404);
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml', 'avif' => 'image/avif',
        ][$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=60');
        header('Content-Length: ' . (string) filesize($real));
        readfile($real);
        exit;
    }

    /**
     * 在候选根中解析出真实文件路径（不命中返回 false）。
     *
     * 每个候选根都做 realpath 越权校验：解析结果必须**确实落在该根之内**
     * （带分隔符比较，"uploads-evil" 之类不会被误放行）。
     */
    private static function locateIn(array $roots, string $relative): string|false
    {
        foreach ($roots as $root) {
            $base = realpath($root);
            if ($base === false || !is_dir($base)) {
                continue;
            }
            $candidate = realpath($base . DIRECTORY_SEPARATOR . $relative);
            if ($candidate === false || !is_file($candidate)) {
                continue;
            }
            if (strncmp($candidate, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
                continue;
            }
            return $candidate;
        }
        return false;
    }

    /**
     * 存储实例所配置的本地根（需要查库，故只在默认根未命中时才调用）：
     * 默认实例优先，其次其余启用的本地实例。
     *
     * 实例表读不到时返回空数组 —— 文件服务不该因一次 DB 抖动而整片 500
     * （调用方此时已试过默认根与历史根）。
     *
     * @return list<string>
     */
    private static function profileRoots(): array
    {
        $roots = [];
        try {
            $default = \App\Models\StorageProfile::defaultProfile();
            if ($default !== null && !$default->isS3() && $default->isEnabled()) {
                $cfg = $default->config();
                $roots[] = LocalDriver::resolveDir((string) ($cfg['path'] ?? ''));
            }
            foreach (\App\Models\StorageProfile::all('sort_order ASC, id ASC') as $profile) {
                if ($profile->isS3() || !$profile->isEnabled()) {
                    continue;
                }
                $cfg = $profile->config();
                $roots[] = LocalDriver::resolveDir((string) ($cfg['path'] ?? ''));
            }
        } catch (\Throwable) {
            // 默认根/历史根已试过，这里失败只意味着自定义路径不可用
        }

        return array_values(array_unique($roots));
    }

    private function abort(int $status): void
    {
        http_response_code($status);
        exit;
    }
}
