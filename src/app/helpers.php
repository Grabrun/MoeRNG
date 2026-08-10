<?php
declare(strict_types=1);

/**
 * MoeRNG - Global Helper Functions
 */

if (!function_exists('h')) {
    function h(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e')) {
    function e(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed {
        return \App\Core\Config::get($key, $default);
    }
}

if (!function_exists('session')) {
    function session(string $key, mixed $default = null): mixed {
        return \App\Core\Session::get($key, $default);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        return \App\Core\Session::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed {
        return \App\Core\Session::flash('_old_input')[$key] ?? $default;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string {
        return '/public/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string {
        $base = \App\Core\Config::get('app.base_url', '');
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): never {
        $response = new \App\Core\Response();
        $response->redirect($url, $status);
        exit;
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): never {
        $response = new \App\Core\Response();
        $response->json($data, $status);
        exit;
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$args): never {
        echo '<pre style="background:#1a1a2e;color:#e8e8f0;padding:16px;border-radius:8px;font-size:13px;line-height:1.6;overflow:auto;max-height:80vh;">';
        foreach ($args as $arg) {
            echo htmlspecialchars(print_r($arg, true));
        }
        echo '</pre>';
        exit;
    }
}
