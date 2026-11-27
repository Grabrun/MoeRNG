<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    public readonly string $method;
    public readonly string $uri;
    public readonly array $query;
    public readonly array $post;
    public readonly array $headers;
    public readonly string $ip;

    /** 由 AuthMiddleware 注入的当前管理员（PHP 8.4 起必须声明属性，不能动态赋值）。 */
    public $user = null;

    public function __construct(
        string $method,
        string $uri,
        array $query = [],
        array $post = [],
        array $headers = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->query = $query;
        $this->post = $post;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->ip = $this->resolveIp();
    }

    private function resolveIp(): string
    {
        // 不信任客户端 X-Forwarded-For（可伪造绕过限流/锁定）；真实地址只取 REMOTE_ADDR。
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json');
    }
}
