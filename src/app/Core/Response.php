<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    private array $headers = [];
    private int $statusCode = 200;

    public function setHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function json(mixed $data, int $status = 200, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo json_encode($data, $flags);
        exit;
    }

    public function redirect(string $url, int $status = 302): never
    {
        // v1.3.0-beta.2 安全加固 (CVE-2026-MR-010, CWE-601): defense-in-depth on
        // every Location header. Callers today pass internal paths or object-
        // storage URLs (the random endpoint 302s to COS/OSS), so the rule is:
        //   - reject header injection (\r\n) unconditionally;
        //   - absolute URLs must be http/https (no javascript:/data:/...);
        //   - everything else must be a site-relative path starting with '/'.
        $url = str_replace(["\r", "\0"], '', $url);
        if (str_contains($url, "\n") || str_starts_with($url, '//')
            || preg_match('/^\s*[\w.+-]+:/i', $url) && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Blocked unsafe redirect target');
        }
        if (!preg_match('#^https?://#i', $url) && !str_starts_with($url, '/')) {
            throw new \InvalidArgumentException('Redirect target must be absolute http(s) URL or site-relative path');
        }
        http_response_code($status);
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        header("Location: {$url}");
        exit;
    }

    public function html(string $content, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo $content;
        exit;
    }

    public function text(string $content, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo $content;
        exit;
    }

    public function file(string $path, string $mime = 'application/octet-stream'): never
    {
        if (!is_file($path)) {
            $this->json(['error' => 'File not found'], 404);
        }
        http_response_code(200);
        header("Content-Type: {$mime}");
        header('Content-Length: ' . filesize($path));
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        readfile($path);
        exit;
    }

    public function image(string $path): never
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
        ];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        $this->file($path, $mime);
    }
}
