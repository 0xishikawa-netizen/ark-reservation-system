<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * お試し環境（APP_ENV=staging）の入口制御（Task 11-36）。
 *
 * - 検索エンジンに載せない（X-Robots-Tag）。
 * - STAGING_BASIC_AUTH_USER / PASSWORD を両方設定したときだけ Basic 認証を掛ける。
 * - Stripe webhook（署名検証で認証）と死活監視 /up は除外する。
 * staging 以外では何もしない。
 */
final class StagingGate
{
    /** Basic 認証を掛けないパス。 */
    private const EXCLUDED_PATHS = ['stripe/webhook', 'up'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('staging')) {
            return $next($request);
        }

        $response = $this->passesBasicAuth($request)
            ? $next($request)
            : response(__('messages.staging.basic_auth_required'), 401, [
                'WWW-Authenticate' => 'Basic realm="ARK staging", charset="UTF-8"',
            ]);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function passesBasicAuth(Request $request): bool
    {
        $user = (string) config('staging.basic_auth.user', '');
        $password = (string) config('staging.basic_auth.password', '');

        if ($user === '' || $password === '' || $request->is(...self::EXCLUDED_PATHS)) {
            return true;
        }

        // 長さの差で一致位置が漏れないよう hash_equals で比較する。
        return hash_equals($user, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());
    }
}
