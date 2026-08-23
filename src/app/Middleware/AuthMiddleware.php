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

        // v1.2.1 登录增强: remember-me auto-login — if there's no session but a
        // valid remember cookie exists, restore the session from it. This lets
        // a checked-in admin return to /admin without re-entering credentials.
        if (!$userId) {
            $raw = (string) ($_COOKIE['moerng_remember'] ?? '');
            if ($raw !== '') {
                try {
                    $user = User::findByRememberToken($raw);
                    if ($user && $user->isActive()) {
                        Session::set('user_id', $user->id);
                        Session::set('user_role', $user->role);
                        Session::set('user_name', (string) $user->username);
                        $userId = $user->id;
                    }
                } catch (\Throwable) {
                    $userId = null; // ignore — fall through to the redirect below
                }
            }
        }

        if (!$userId) {
            // Redirect to login for non-AJAX requests
            if ($request->wantsJson()) {
                $response = new Response();
                $response->json(['error' => 'Unauthorized', 'message' => 'Please login first.'], 401);
            }
            $response = new Response();
            $response->redirect('/admin/login');
        }

        $user = User::find($userId);
        if (!$user || !$user->isActive()) {
            // Clear only the auth keys (don't nuke the whole session, so a
            // flash message can still reach the login page), then bounce.
            Session::remove('user_id');
            Session::remove('user_role');
            $response = new Response();
            $response->redirect('/admin/login');
        }

        // Store current user in request context
        $request->user = $user;

        return $next($request);
    }
}
