<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    private array $headers = [];
    private int $statusCode = 200;

    public function json(mixed $data, int $status = 200, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }

        // json_encode 失败会输出空响应体，故加 JSON_INVALID_UTF8_SUBSTITUTE；仍失败则降级为合法 JSON 并带错误原因。
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        $body = json_encode($data, $flags);
        if ($body === false) {
            $fallback = json_encode([
                'success' => false,
                'error'   => 'JSON 编码失败：' . json_last_error_msg() . '（返回数据含无法编码的内容）',
                'json_encode_failed' => true,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            echo $fallback !== false ? $fallback : '{"success":false,"error":"response encoding failed"}';
            exit;
        }
        echo $body;
        exit;
    }

    public function redirect(string $url, int $status = 302): never
    {
        // Location 防 CRLF 注入和开放重定向：只允许 http(s) 绝对地址或站点相对路径。
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
}
