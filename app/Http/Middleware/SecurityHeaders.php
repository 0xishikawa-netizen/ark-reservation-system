<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()',
        );
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        if ($request->is('admin') || $request->is('admin/*')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        if (app()->environment('local')) {
            return "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' ws: http://localhost:5173; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
        }

        return "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
    }
}
