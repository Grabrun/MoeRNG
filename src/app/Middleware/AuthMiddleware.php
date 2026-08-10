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
