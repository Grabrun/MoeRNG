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

        // Anchor to the local upload dir; reject path traversal.
        $driver = new LocalDriver();
        $file = rtrim($driver->uploadDir(), '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $real = realpath($file);
        $base = realpath($driver->uploadDir());
        if ($real === false || $base === false || strncmp($real, $base, strlen($base)) !== 0 || !is_file($real)) {
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

    private function abort(int $status): void
    {
        http_response_code($status);
        exit;
    }
}
