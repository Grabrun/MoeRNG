<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Models\Setting;
use App\Models\ApiKey;

class ApiKeyAuthMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        if (Setting::get('api_key_auth_required', '0') !== '1') {
            return $next($request);
        }

        $apiKey = (string) $request->header('x-api-key', '');
        $keyModel = $apiKey !== '' ? ApiKey::findByKey($apiKey) : null;
        if (!$keyModel || ($keyModel->status ?? '') !== 'active') {
            $response = new Response();
            $response->json([
                'error' => 'Unauthorized',
                'message' => 'API Key required. Pass it via the X-API-Key header.',
            ], 401);
            return null;
        }

        return $next($request);
    }
}
