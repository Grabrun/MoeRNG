<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Models\Setting;
use App\Models\ApiKey;

/**
 * v1.2.1 迭代: optional API-Key auth gate for /api/v1/*.
 *
 * Controlled by settings.api_key_auth_required (default OFF). When enabled,
 * every public API request MUST present a valid active API key via the
 * X-API-Key header, otherwise a 401 is returned. The master switch keeps
 * the public-API behaviour unchanged for existing consumers until the
 * operator explicitly opts in.
 */
class ApiKeyAuthMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        // Optional switch — default off keeps the public API open.
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
