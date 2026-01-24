<?php

namespace App\Http\Middleware;

use App\Contexts\ApiContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware terminable per resettare l'ApiContext alla fine di ogni request.
 *
 * Importante per evitare memory leaks quando si usa Laravel Octane
 * o altri ambienti dove la stessa istanza PHP gestisce più richieste.
 */
class ResetApiContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        ApiContext::reset();
    }
}
