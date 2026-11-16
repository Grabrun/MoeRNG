<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * CSRF 校验的**唯一归属**（v2.0.0-beta.19）。
 *
 * 此前有两道：本中间件 + 每个控制器里的 `Controller::validateCsrf()`（37 处调用）。
 * 重复校验不只是冗余 —— 中间件先返回 419，而控制器里为「POST 体被 post_max_size
 * 丢弃」专门写的 413 提示在 `/admin/*` 上**永远到不了**：上传超大文件时用户看到的是
 * 「CSRF token validation failed」，而不是「请分批上传」。
 *
 * 现在：校验与 413 判定都只在这里，控制器不再参与。
 */
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
                // 超大 POST 会让 PHP 直接丢弃整个请求体（$_POST / $_FILES 皆空），
                // 此时 token 必然为空 —— 报 413 比 419 更能说明真实原因。
                if ($token === '' && self::bodyExceededPostMax()) {
                    (new Response())->json([
                        'success' => false,
                        'error' => '上传请求超过服务器限制（post_max_size = ' . ini_get('post_max_size')
                            . '，单文件限制 ' . ini_get('upload_max_filesize')
                            . '），请分批上传（建议一次 5-10 张）。',
                    ], 413);
                    // Response::json() 是 never（内部 exit），这行只为把短路写明确。
                    return null;
                }
                (new Response())->json(['success' => false, 'error' => 'CSRF token validation failed.'], 419);
                return null;
            }
        }
        return $next($request);
    }

    /** 请求体是否很可能已被 post_max_size 丢弃。 */
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

    /** 把 "8M" / "1G" 这类 ini 值换算为字节（无法解析返回 0）。 */
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
