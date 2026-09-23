<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中にアカウントを無効化された場合、次のリクエストで即座にログアウトさせる
 * （無効化はログイン時のチェックだけでは既存セッションに効かないため）。
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active === false) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('login'))
                ->withErrors(['email' => __('messages.auth.account_disabled')]);
        }

        return $next($request);
    }
}
