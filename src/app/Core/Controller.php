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

        require_once dirname(__DIR__) . '/helpers.php';

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
        exit;
    }

    protected function redirect(string $url): never
    {
        // 重定向前先把 session 落盘，否则下个请求读不到本次写入（如登录 user_id）。
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $response = new Response();
        $response->redirect($url);
        exit;
    }

    /** 调用方是否期望 JSON（fetch 请求）而非跳转。 */
    protected function isAjax(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** XHR 返回 JSON，否则 flash + 跳转。 */
    protected function ok(string $message, array $payload = [], string $redirectTo = '/admin'): never
    {
        if ($this->isAjax()) {
            $this->json(['success' => true, 'message' => $message] + $payload);
        }
        Session::flash('success', $message);
        $this->redirect($redirectTo);
    }

    /** XHR 返回 JSON，否则 flash + 跳转。 */
    protected function fail(string $message, int $status = 400, string $redirectTo = '/admin'): never
    {
        if ($this->isAjax()) {
            $this->json(['success' => false, 'error' => $message], $status);
        }
        Session::flash('error', $message);
        $this->redirect($redirectTo);
    }
}
