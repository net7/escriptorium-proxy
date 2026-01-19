<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public const HEADER_NAME = 'X-API-Key';

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

        // Check rate limit
        $rateLimitKey = $apiKey->rateLimiterKey();
        $maxAttempts = $apiKey->rate_limit;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->rateLimitedResponse($retryAfter, $maxAttempts);
        }

        RateLimiter::hit($rateLimitKey, 60); // Decay in 60 seconds

        // Log the request if enabled
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
        $request->attributes->set('api_key_log', $log);

        // Execute the request
        $response = $next($request);

        // Record response metrics
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Log the response if enabled
        if (config('apikey.enable_logging')) {
            $log->recordResponse($response->getStatusCode(), $responseTimeMs);
        }

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($rateLimitKey, $maxAttempts));

        return $response;
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
