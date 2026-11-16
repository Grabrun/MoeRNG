<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

class CsrfMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = (string) $request->input('_csrf_token', '');
            if ($token === '') {
                $token = (string) $request->header('x-csrf-token', '');
            }

            if (!Session::verifyCsrf($token)) {
                // 超大 POST 会让 PHP 丢弃整个请求体，此时 token 必为空，报 413 比 419 更准确。
                if ($token === '' && self::bodyExceededPostMax()) {
                    (new Response())->json([
                        'success' => false,
                        'error' => '上传请求超过服务器限制（post_max_size = ' . ini_get('post_max_size')
                            . '，单文件限制 ' . ini_get('upload_max_filesize')
                            . '），请分批上传（建议一次 5-10 张）。',
                    ], 413);
                    return null;
                }
                (new Response())->json(['success' => false, 'error' => 'CSRF token validation failed.'], 419);
                return null;
            }
        }
        return $next($request);
    }

    private static function bodyExceededPostMax(): bool
    {
        $cl = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = self::iniBytes((string) ini_get('post_max_size'));
        if ($cl > 0 && $max > 0 && $cl > $max) {
            return true;
        }
        foreach (($_FILES ?? []) as $f) {
            $errs = isset($f['error']) ? (is_array($f['error']) ? $f['error'] : [$f['error']]) : [];
            foreach ($errs as $e) {
                if ((int) $e === UPLOAD_ERR_INI_SIZE) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function iniBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = strtolower(substr($val, -1));
        $num = (int) $val;
        return match ($unit) {
            'g' => $num * 1073741824,
            'm' => $num * 1048576,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
