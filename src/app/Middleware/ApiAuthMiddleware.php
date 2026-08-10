<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Models\ApiKey;

class ApiAuthMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $key = $request->header('x-api-key', '');

        if (empty($key)) {
            $response = new Response();
            $response->json([
                'error' => 'Missing API Key',
                'message' => 'Please provide an API key via the X-API-Key header.',
            ], 401);
        }

        $apiKey = ApiKey::validate($key);
        if (!$apiKey) {
            $response = new Response();
            $response->json([
                'error' => 'Invalid API Key',
                'message' => 'The provided API key is invalid or has been disabled.',
            ], 403);
        }

        $request->apiKey = $apiKey;

        return $next($request);
    }
}
