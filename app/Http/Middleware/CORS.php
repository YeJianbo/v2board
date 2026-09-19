<?php

namespace App\Http\Middleware;

use Closure;

class CORS
{
    public function handle($request, Closure $next)
    {
        $origin = $request->header('origin');
        $response = $next($request);

        if (!$origin || !$this->originIsAllowed($origin)) {
            return $response;
        }

        $supportsCredentials = (bool) config('cors.supports_credentials', false);
        $allowedOrigins = (array) config('cors.allowed_origins', ['*']);
        $allowOrigin = in_array('*', $allowedOrigins, true) && !$supportsCredentials ? '*' : $origin;
        $methods = (array) config('cors.allowed_methods', ['GET', 'POST', 'OPTIONS', 'HEAD']);
        $headers = (array) config('cors.allowed_headers', ['Origin', 'Content-Type', 'Accept', 'Authorization', 'X-Request-With']);

        $response->header('Access-Control-Allow-Origin', $allowOrigin);
        $response->header('Access-Control-Allow-Methods', implode(',', $methods));
        $response->header('Access-Control-Allow-Headers', implode(',', $headers));
        $response->header('Access-Control-Max-Age', (string) config('cors.max_age', 10080));
        $response->header('Vary', 'Origin', false);
        if ($supportsCredentials) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function originIsAllowed(string $origin): bool
    {
        $allowedOrigins = (array) config('cors.allowed_origins', ['*']);
        if (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        foreach ((array) config('cors.allowed_origins_patterns', []) as $pattern) {
            if (@preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }
}
