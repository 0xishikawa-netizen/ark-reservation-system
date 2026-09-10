<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleFortifyRequests
{
    public function __construct(
        private readonly ThrottleRequests $throttleRequests,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register.store')) {
            return $this->throttleRequests->handle($request, $next, 'register');
        }

        if ($request->routeIs('password.email')) {
            return $this->throttleRequests->handle($request, $next, 'password-reset');
        }

        // 再認証（password.confirm）はセッション侵害時の封じ込め装置。
        // Fortify は既定でレート制限を掛けないため、ここで総当たりを止める。
        if ($request->routeIs('password.confirm.store')) {
            return $this->throttleRequests->handle($request, $next, 'password-confirm');
        }

        return $next($request);
    }
}
