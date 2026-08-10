<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function render(string $view, array $data = []): Response
    {
        $viewPath = dirname(__DIR__, 2) . '/views/' . $view . '.php';
        if (!is_file($viewPath)) {
            http_response_code(500);
            echo "View '{$view}' not found.";
            exit;
        }

        // Load global helpers
        require_once dirname(__DIR__) . '/helpers.php';

        // CSRF token for forms
        $data['csrf_token'] = Session::csrfToken();
        $data['csrf_field'] = '<input type="hidden" name="_csrf_token" value="' . $data['csrf_token'] . '">';

        extract($data);
        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        $response = new Response();
        $response->html($content);
        return $response;
    }

    protected function json(mixed $data, int $status = 200): never
    {
        $response = new Response();
        $response->json($data, $status);
        exit; // unreachable
    }

    protected function redirect(string $url): never
    {
        // Flush the session to disk BEFORE the redirect exits. Values written
        // to $_SESSION earlier in this request (e.g. the login user_id) must
        // be persisted, otherwise the next request (often a redirect target
        // behind auth middleware) reads an empty session and bounces back.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $response = new Response();
        $response->redirect($url);
        exit; // unreachable
    }

    protected function back(): never
    {
        $url = $_SERVER['HTTP_REFERER'] ?? '/';
        $this->redirect($url);
    }

    protected function validateCsrf(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Session::verifyCsrf($token)) {
            $this->json(['error' => 'CSRF token validation failed.'], 419);
        }
    }

    protected function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Whether the caller expects JSON rather than a redirect.
     *
     * Admin actions are driven by fetch() and must never navigate the browser
     * to a raw JSON endpoint; plain form posts (no JS) still get a redirect.
     */
    protected function isAjax(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** JSON success for XHR, flash + redirect otherwise. */
    protected function ok(string $message, array $payload = [], string $redirectTo = '/admin'): never
    {
        if ($this->isAjax()) {
            $this->json(['success' => true, 'message' => $message] + $payload);
        }
        Session::flash('success', $message);
        $this->redirect($redirectTo);
    }

    /** JSON failure for XHR, flash + redirect otherwise. */
    protected function fail(string $message, int $status = 400, string $redirectTo = '/admin'): never
    {
        if ($this->isAjax()) {
            $this->json(['success' => false, 'error' => $message], $status);
        }
        Session::flash('error', $message);
        $this->redirect($redirectTo);
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    protected function allInput(): array
    {
        if ($this->isPost()) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $body = file_get_contents('php://input') ?: '';
                return json_decode($body, true) ?: [];
            }
            return $_POST;
        }
        return $_GET;
    }
}
