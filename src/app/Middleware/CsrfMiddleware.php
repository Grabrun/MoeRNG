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
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $token = $request->input('_csrf_token', '');
            if (empty($token)) {
                // Try from header
                $token = $request->header('x-csrf-token', '');
            }
            if (!Session::verifyCsrf($token)) {
                $response = new Response();
                $response->json(['error' => 'CSRF token validation failed.'], 419);
            }
        }
        return $next($request);
    }
}
