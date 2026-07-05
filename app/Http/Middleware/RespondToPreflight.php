<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RespondToPreflight
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPreflight($request)) {
            return $next($request);
        }

        $origin = (string) $request->headers->get('Origin');

        if ($origin === '' || ! $this->isOriginAllowed($origin)) {
            return $next($request);
        }

        $allowedOrigin = $this->resolveAllowedOriginHeader($origin);

        $response = response('', 204);
        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);

        if ($allowedOrigin !== '*') {
            $response->headers->set('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
        }

        if ($request->headers->has('Access-Control-Request-Method')) {
            $response->headers->set(
                'Access-Control-Allow-Methods',
                implode(', ', $this->allowedMethods()),
            );
        }

        $requestedHeaders = (string) $request->headers->get('Access-Control-Request-Headers');

        if ($requestedHeaders !== '') {
            $response->headers->set('Access-Control-Allow-Headers', $requestedHeaders);
        }

        $maxAge = (int) config('cors.max_age', 0);

        if ($maxAge > 0) {
            $response->headers->set('Access-Control-Max-Age', (string) $maxAge);
        }

        return $response;
    }

    private function isPreflight(Request $request): bool
    {
        return $request->isMethod('OPTIONS')
            && $request->headers->has('Access-Control-Request-Method')
            && $request->is('api/*', 'oauth/*');
    }

    private function isOriginAllowed(string $origin): bool
    {
        $allowedOrigins = config('cors.allowed_origins', []);

        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        foreach (config('cors.allowed_origins_patterns', []) as $pattern) {
            if ($pattern !== '' && preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedMethods(): array
    {
        $methods = config('cors.allowed_methods', ['*']);

        if (in_array('*', $methods, true)) {
            return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }

        return array_map('strtoupper', $methods);
    }

    private function allowsAnyOrigin(): bool
    {
        return in_array('*', config('cors.allowed_origins', []), true);
    }

    private function resolveAllowedOriginHeader(string $origin): string
    {
        if ($this->allowsAnyOrigin()) {
            return '*';
        }

        return $origin;
    }
}
