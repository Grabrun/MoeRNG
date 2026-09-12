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

        // v1.5.0-beta.1: 本地媒体根可能同时存在于多处（迁根前/后、多实例、自定义
        // 路径），因此按优先级逐个候选根尝试解析，任一命中即可 —— 迁根期间与迁移
        // 失败时图片都不会 404。每个候选根都做 realpath 越权校验。
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $real = false;
        foreach (self::candidateRoots() as $root) {
            $base = realpath($root);
            if ($base === false || !is_dir($base)) {
                continue;
            }
            $candidate = realpath($base . DIRECTORY_SEPARATOR . $relative);
            if ($candidate === false || !is_file($candidate)) {
                continue;
            }
            // 必须确实落在本根之内（带分隔符比较，"uploads-evil" 之类不被误放行）
            if (strncmp($candidate, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
                continue;
            }
            $real = $candidate;
            break;
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
     * 可供读取的本地根（绝对路径），顺序即优先级：
     *   1. 启用的本地存储实例所配置的目录（URL 就是按各自实例生成的）
     *   2. 驱动默认根 storage/uploads
     *   3. 历史根 public/uploads（迁根未执行/失败时的回退）
     *
     * 实例表读不到时退回默认根 —— 文件服务不该因一次 DB 抖动而整片 500。
     *
     * @return list<string>
     */
    private static function candidateRoots(): array
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
            // fall through to the default roots
        }

        $roots[] = LocalDriver::defaultUploadDir();
        $roots[] = LocalDriver::legacyUploadDir();

        return array_values(array_unique($roots));
    }

    private function abort(int $status): void
    {
        http_response_code($status);
        exit;
    }
}
