<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;

class AuthMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $userId = Session::get('user_id');

        // 无 session 时用 remember cookie 恢复登录态。
        if (!$userId) {
            $raw = (string) ($_COOKIE['moerng_remember'] ?? '');
            if ($raw !== '') {
                try {
                    $user = User::findByRememberToken($raw);
                    if ($user && $user->isActive()) {
                        // cookie 自动登录同样是一次登录态变更，必须换新 session id。
                        Session::regenerate();
                        Session::set('user_id', $user->id);
                        Session::set('user_role', $user->role);
                        Session::set('user_name', (string) $user->username);
                        $userId = $user->id;
                    }
                } catch (\Throwable) {
                    $userId = null;
                }
            }
        }

        if (!$userId) {
            if ($request->wantsJson()) {
                $response = new Response();
                $response->json(['error' => 'Unauthorized', 'message' => 'Please login first.'], 401);
            }
            $response = new Response();
            $response->redirect('/admin/login');
        }

        $user = User::find($userId);
        if (!$user || !$user->isActive()) {
            // 只清认证键，保留 session，让 flash 消息仍能送达登录页。
            Session::remove('user_id');
            Session::remove('user_role');
            $response = new Response();
            $response->redirect('/admin/login');
        }

        $request->user = $user;

        return $next($request);
    }
}
