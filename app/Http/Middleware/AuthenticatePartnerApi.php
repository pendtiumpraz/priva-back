<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Models\PartnerApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthenticatePartnerApi
{
    /**
     * Handle an incoming partner API request.
     * Expects header: X-Api-Key: pk_live_xxxxx
     */
    public function handle(Request $request, Closure $next, string $requiredPermission = '*')
    {
        $startTime = microtime(true);
        $apiKey = $request->header('X-Api-Key');

        if (!$apiKey) {
            return $this->errorResponse('API key diperlukan. Kirim via header X-Api-Key.', 401);
        }

        // Extract prefix for fast lookup
        $prefix = substr($apiKey, 0, 12) . '...' . substr($apiKey, -4);

        // Find key by prefix (cached for performance)
        $keyModel = Cache::remember("api_key:{$prefix}", 300, function () use ($prefix) {
            return PartnerApiKey::where('key_prefix', $prefix)
                ->where('is_active', true)
                ->first();
        });

        if (!$keyModel) {
            return $this->errorResponse('API key tidak valid.', 401);
        }

        // Verify hash
        if (!$keyModel->verifyKey($apiKey)) {
            Cache::forget("api_key:{$prefix}");
            return $this->errorResponse('API key tidak valid.', 401);
        }

        // Check expiry
        if ($keyModel->expires_at && $keyModel->expires_at->isPast()) {
            return $this->errorResponse('API key sudah kedaluwarsa.', 401);
        }

        // Check IP whitelist
        if ($keyModel->allowed_ips && count($keyModel->allowed_ips) > 0) {
            if (!in_array($request->ip(), $keyModel->allowed_ips)) {
                return $this->errorResponse('IP address tidak diizinkan.', 403);
            }
        }

        // Check permission
        if ($requiredPermission !== '*' && !$keyModel->hasPermission($requiredPermission)) {
            return $this->errorResponse("Permission '{$requiredPermission}' tidak dimiliki.", 403);
        }

        // Rate limiting
        $rateLimitKey = "api_rate:{$keyModel->id}:" . now()->format('Y-m-d-H-i');
        $currentCount = (int) Cache::get($rateLimitKey, 0);

        if ($currentCount >= $keyModel->rate_limit_per_minute) {
            return $this->errorResponse('Rate limit terlampaui. Tunggu 1 menit.', 429);
        }

        Cache::put($rateLimitKey, $currentCount + 1, 120);

        // Set context for controller
        $request->attributes->set('api_key', $keyModel);
        $request->attributes->set('api_org_id', $keyModel->org_id);

        // Execute request
        $response = $next($request);

        // Log request async
        $responseTime = round((microtime(true) - $startTime) * 1000);
        try {
            ApiRequestLog::create([
                'api_key_id' => $keyModel->id,
                'org_id' => $keyModel->org_id,
                'method' => $request->method(),
                'endpoint' => '/' . ltrim($request->path(), '/'),
                'status_code' => $response->getStatusCode(),
                'response_time_ms' => $responseTime,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'created_at' => now(),
            ]);

            $keyModel->recordRequest();
        } catch (\Exception $e) {
            // Don't block response if logging fails
        }

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $keyModel->rate_limit_per_minute);
        $response->headers->set('X-RateLimit-Remaining', max(0, $keyModel->rate_limit_per_minute - $currentCount - 1));

        return $response;
    }

    private function errorResponse(string $message, int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => true,
            'message' => $message,
        ], $status);
    }
}
