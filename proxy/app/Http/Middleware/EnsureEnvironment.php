<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that restricts access to specific application environments.
 * Returns 404 if the current environment is not in the allowed list.
 *
 * Usage:
 *   - Route::middleware('env:local')           - allows only local
 *   - Route::middleware('env:local,staging')   - allows local OR staging
 *   - Route::middleware('env:local|staging')   - alternative syntax with pipe
 *   - Route::middleware('env:!production')     - allows any except production
 */
class EnsureEnvironment
{
    /**
     * Environment group aliases for convenience.
     */
    protected array $groups = [
        'development' => ['local', 'staging'],
        'deployed' => ['staging', 'production'],
    ];

    /**
     * Handle an incoming request.
     *
     * @param  string  ...$environments  Allowed environments, group names, or negated environments (!production)
     */
    public function handle(Request $request, Closure $next, string ...$environments): Response
    {
        if (empty($environments)) {
            $environments = ['local'];
        }

        $allowed = $this->resolveEnvironments($environments);

        if ($this->isAllowed($allowed)) {
            return $next($request);
        }

        abort(404);
    }

    /**
     * Resolve environment parameters, expanding groups and handling negations.
     */
    protected function resolveEnvironments(array $environments): array
    {
        $allowed = [];
        $denied = [];

        foreach ($environments as $env) {
            // Support pipe syntax: "local|staging" -> ["local", "staging"]
            $parts = preg_split('/[,|]/', $env);

            foreach ($parts as $part) {
                $part = trim($part);

                if (str_starts_with($part, '!')) {
                    // Negation: !production means deny production
                    $denied[] = substr($part, 1);
                } elseif (isset($this->groups[$part])) {
                    // Group alias: development -> [local, staging]
                    $allowed = array_merge($allowed, $this->groups[$part]);
                } else {
                    $allowed[] = $part;
                }
            }
        }

        return [
            'allowed' => array_unique($allowed),
            'denied' => array_unique($denied),
        ];
    }

    /**
     * Check if current environment is allowed.
     */
    protected function isAllowed(array $resolved): bool
    {
        $current = app()->environment();

        // If explicitly denied, reject
        if (in_array($current, $resolved['denied'], true)) {
            return false;
        }

        // If no allowed list (only denials), allow anything not denied
        if (empty($resolved['allowed'])) {
            return true;
        }

        // Check if current environment is in allowed list
        return in_array($current, $resolved['allowed'], true);
    }
}
