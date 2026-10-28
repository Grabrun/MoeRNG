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
    public readonly string $body;
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
        array $headers = [],
        string $body = ''
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->params = $params;
        $this->query = $query;
        $this->post = $post;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
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

    public function json(): array
    {
        if (empty($this->body)) {
            return [];
        }
        return json_decode($this->body, true) ?: [];
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json');
    }

    /**
     * 按 `required|min:N|max:N|email|numeric` 规则校验输入。
     *
     * @param array<string,string> $rules field => 竖线分隔的规则串
     * @return array{0: array<string,string[]>, 1: array<string,mixed>} [错误列表, 归一化后的输入]
     */
    public function validate(array $rules): array
    {
        $errors = [];
        $data = [];

        foreach ($rules as $field => $rule) {
            $value = $this->input($field);
            $ruleParts = explode('|', $rule);

            foreach ($ruleParts as $part) {
                if ($part === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "{$field} is required.";
                }
                // min/max 按**字符**计（mb_strlen），不是字节：strlen 会把中文按
                // 3 字节/字计数，min:8 实际只要求 3 个汉字。本项目是中文优先。
                if (str_starts_with($part, 'min:')) {
                    $min = (int) substr($part, 4);
                    if (mb_strlen((string)$value) < $min) {
                        $errors[$field][] = "{$field} must be at least {$min} characters.";
                    }
                }
                if (str_starts_with($part, 'max:')) {
                    $max = (int) substr($part, 4);
                    if (mb_strlen((string)$value) > $max) {
                        $errors[$field][] = "{$field} must not exceed {$max} characters.";
                    }
                }
                if ($part === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} must be a valid email address.";
                }
                if ($part === 'numeric' && $value !== '' && $value !== null && !is_numeric($value)) {
                    $errors[$field][] = "{$field} must be numeric.";
                }
            }

            $data[$field] = $value;
        }

        if (!empty($errors)) {
            $response = new Response();
            $response->json(['error' => 'Validation failed', 'errors' => $errors], 422);
            exit;
        }

        return $data;
    }
}
