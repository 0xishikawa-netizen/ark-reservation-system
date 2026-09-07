<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffTwoFactor
{
    /** @var list<string> */
    private const EXCLUDED_ROUTES = [
        'admin.two-factor-setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'logout',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::EXCLUDED_ROUTES)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user?->hasAnyRole(['staff', 'manager', 'admin']) === true
            && $user->two_factor_confirmed_at === null) {
            return redirect()->route('admin.two-factor-setup');
        }

        return $next($request);
    }
}
