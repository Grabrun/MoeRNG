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
