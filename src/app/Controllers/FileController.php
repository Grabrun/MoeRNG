<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Storage\LocalDriver;
use App\Storage\SignedUrl;

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
            $this->abort(410);
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $real = self::locateIn([LocalDriver::defaultUploadDir()], $relative);
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

        // 清掉输出缓冲，避免 gzip 把整个文件读进内存（否则大图内存翻倍且失去流式）。
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=60');
        header('Content-Length: ' . (string) filesize($real));

        // SVG 可执行脚本，非图片类型强制 attachment，防止本站源的存储型 XSS。
        header('X-Content-Type-Options: nosniff');
        $inlineSafe = in_array($mime, [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'image/bmp', 'image/avif',
        ], true);
        header('Content-Disposition: ' . ($inlineSafe ? 'inline' : 'attachment')
            . '; filename="' . self::safeDownloadName(basename($real)) . '"');

        readfile($real);
        exit;
    }

    /** 去掉引号/控制字符与路径分隔符，防止响应头注入。 */
    private static function safeDownloadName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'download';
        }
        return $name;
    }

    /** realpath 结果必须落在候选根之内，防止路径穿越。 */
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

    /** 存储实例配置的本地根。@return list<string> */
    private static function profileRoots(): array
    {
        $roots = [];
        try {
            foreach (\App\Models\StorageProfile::all('sort_order ASC, id ASC') as $profile) {
                if ($profile->isS3() || !$profile->isEnabled()) {
                    continue;
                }
                $cfg = $profile->config();
                $roots[] = LocalDriver::resolveDir((string) ($cfg['path'] ?? ''));
            }
        } catch (\Throwable) {
            // 实例表不可读时返回空，文件服务不因一次 DB 抖动整片 500。
        }

        return array_values(array_unique($roots));
    }

    private function abort(int $status): void
    {
        http_response_code($status);
        exit;
    }
}
