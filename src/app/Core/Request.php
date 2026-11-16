<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    public readonly string $method;
    public readonly string $uri;
    public readonly array $params;
    public readonly array $query;
    public readonly array $post;
    public readonly array $headers;
    public readonly string $ip;

    /** Authenticated admin user, set by AuthMiddleware (PHP 8.4+ requires
     *  declared properties — assigning it dynamically is deprecated). */
    public $user = null;

    public function __construct(
        string $method,
        string $uri,
        array $params = [],
        array $query = [],
        array $post = [],
        array $headers = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->params = $params;
        $this->query = $query;
        $this->post = $post;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->ip = $this->resolveIp();
    }

    private function resolveIp(): string
    {
        // v1.2.1 security: NEVER trust the client-supplied X-Forwarded-For —
        // attackers can rotate it to defeat IP-based rate limiting / login
        // lockout. The real client address is REMOTE_ADDR only (a reverse
        // proxy layer is responsible for overwriting REMOTE_ADDR with the
        // true peer when one is deployed).
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
