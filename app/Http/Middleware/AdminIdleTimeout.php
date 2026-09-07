<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Settings\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminIdleTimeout
{
    private const LAST_ACTIVITY_KEY = 'admin.last_activity';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lastActivity = $request->session()->get(self::LAST_ACTIVITY_KEY);
        $timeout = (int) app(Settings::class)->get(
            'admin.idle_timeout',
            config('admin.idle_timeout', 1800),
        );
        $now = now()->timestamp;

        if (is_int($lastActivity) && $now - $lastActivity > $timeout) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('login', ['expired' => 1]));
        }

        $request->session()->put(self::LAST_ACTIVITY_KEY, $now);

        return $next($request);
    }
}
