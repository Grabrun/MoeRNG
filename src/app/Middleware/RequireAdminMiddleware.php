<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

class RequireAdminMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $role = null;

        if ($request->user !== null) {
            $role = $request->user->role ?? null;
        }
        if ($role === null) {
            $role = Session::get('user_role');
        }

        if ($role !== 'admin') {
            if ($request->wantsJson()) {
                $response = new Response();
                $response->json([
                    'error' => 'Forbidden',
                    'message' => 'Admin role required.',
                ], 403);
            }

            Session::flash('error', '需要管理员权限才能执行该操作。');
            $response = new Response();
            $response->redirect('/admin');
        }

        return $next($request);
    }
}
