<?php

namespace App\Http\Middleware;

use App\Contexts\ApiContext;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public const string HEADER_NAME = 'X-API-Key';

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string|null  $permission  Optional permission to check
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $startTime = microtime(true);

        $plainKey = $request->header(self::HEADER_NAME);

        if (! $plainKey) {
            return $this->unauthorizedResponse('API key is required');
        }

        // Check if it's a direct eScriptorium API key
        $escriptoriumApiKey = DB::connection('escriptorium')->table('authtoken_token')->where('key', $plainKey)->first();

        if ($escriptoriumApiKey) {
            return $this->handleEscriptoriumDirectKey($request, $next, $escriptoriumApiKey, $plainKey, $startTime);
        }

        // Otherwise, check if it's a Laravel-managed API key
        $apiKey = ApiKey::findByKey($plainKey);

        if (! $apiKey) {
            return $this->unauthorizedResponse('Invalid API key');
        }

        if (! $apiKey->isValid()) {
            return $this->unauthorizedResponse('API key is inactive or expired');
        }

        // Check permission if specified
        if ($permission && ! $apiKey->hasPermission($permission)) {
            return $this->forbiddenResponse("Insufficient permissions for: {$permission}");
        }

        // Set service auth mode
        ApiContext::setServiceAuth();

        return $this->processRequest($request, $next, $apiKey, $startTime, false, null);
    }

    /**
     * Handle a direct eScriptorium API key.
     */
    protected function handleEscriptoriumDirectKey(
        Request $request,
        Closure $next,
        object $escriptoriumApiKey,
        string $plainKey,
        float $startTime
    ): Response {
        // Set direct token mode
        ApiContext::setDirectToken($plainKey);

        // Get or create a virtual ApiKey for logging and rate limiting
        $apiKey = $this->getOrCreateVirtualApiKey($escriptoriumApiKey);

        return $this->processRequest($request, $next, $apiKey, $startTime, true, $plainKey);
    }

    /**
     * Process the request with rate limiting, logging, and response handling.
     */
    protected function processRequest(
        Request $request,
        Closure $next,
        ApiKey $apiKey,
        float $startTime,
        bool $isEscriptoriumDirect,
        ?string $escriptoriumToken
    ): Response {
        // Check rate limit
        $rateLimitKey = $apiKey->rateLimiterKey();
        $maxAttempts = $apiKey->rate_limit;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->rateLimitedResponse($retryAfter, $maxAttempts);
        }

        RateLimiter::hit($rateLimitKey, 60); // Decay in 60 seconds

        // Log the request if enabled
        $log = null;
        if (config('apikey.enable_logging')) {
            $log = $apiKey->logRequest(
                $request->path(),
                $request->method(),
                $request->ip(),
                $request->userAgent()
            );
        }

        // Update last used timestamp
        $apiKey->recordUsage();

        // Bind API key to request for later use
        $request->attributes->set('api_key', $apiKey);

        // Bind log to request for later use if logging is enabled
        if (config('apikey.enable_logging') && $log) {
            $request->attributes->set('api_key_log', $log);
        }

        // Execute the request
        $response = $next($request->merge([
            'is_escriptorium_api_key' => $isEscriptoriumDirect,
            'direct_mode_token' => $escriptoriumToken,
        ]));

        // Record response metrics
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Log the response if enabled
        if (config('apikey.enable_logging') && $log) {
            $log->recordResponse($response->getStatusCode(), $responseTimeMs);
        }

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($rateLimitKey, $maxAttempts));

        return $response;
    }

    /**
     * Get or create a virtual ApiKey for an eScriptorium user.
     *
     * This allows us to use the same logging and rate limiting infrastructure
     * for both Laravel-managed keys and direct eScriptorium tokens.
     */
    protected function getOrCreateVirtualApiKey(object $escriptoriumApiKey): ApiKey
    {
        return ApiKey::firstOrCreate(
            [
                'is_escriptorium_direct' => true,
                'escriptorium_user_id' => $escriptoriumApiKey->user_id,
            ],
            [
                'name' => 'eScriptorium Direct User #'.$escriptoriumApiKey->user_id,
                'key_hash' => hash('sha256', 'escriptorium_virtual_'.$escriptoriumApiKey->user_id),
                'key_prefix' => 'esd_virtual',
                'permissions' => ['*'],
                'rate_limit' => config('apikey.default_rate_limit', 1000),
                'is_active' => true,
            ]
        );
    }

    protected function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Unauthorized',
            'message' => $message,
        ], Response::HTTP_UNAUTHORIZED);
    }

    protected function forbiddenResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Forbidden',
            'message' => $message,
        ], Response::HTTP_FORBIDDEN);
    }

    protected function rateLimitedResponse(int $retryAfter, int $maxAttempts): Response
    {
        return response()->json([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded',
            'retry_after' => $retryAfter,
        ], Response::HTTP_TOO_MANY_REQUESTS)
            ->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
    }
}
