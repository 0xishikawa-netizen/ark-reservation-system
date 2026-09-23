<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Stripe Payment Element が必要とする公式ドメイン（PLAN §13 / phase-05 §6-1）。
     * ワイルドカードは使わず、必要な最小限だけを許可する。
     */
    private const STRIPE_SCRIPT = 'https://js.stripe.com';

    private const STRIPE_FRAME = 'https://js.stripe.com https://hooks.stripe.com';

    private const STRIPE_CONNECT = 'https://api.stripe.com https://js.stripe.com';

    private const STRIPE_IMG = 'https://*.stripe.com';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isAdmin = $request->is('admin') || $request->is('admin/*');

        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Permissions-Policy', $this->permissionsPolicy($isAdmin));
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($isAdmin));

        if ($isAdmin) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }

    /**
     * 管理画面では決済 API を封じたまま。顧客側のみ Payment Element / ウォレット決済を許可する。
     */
    private function permissionsPolicy(bool $isAdmin): string
    {
        $payment = $isAdmin ? 'payment=()' : 'payment=(self "'.self::STRIPE_SCRIPT.'")';

        return 'camera=(), microphone=(), geolocation=(), '.$payment;
    }

    private function contentSecurityPolicy(bool $isAdmin): string
    {
        $local = app()->environment('local');

        // 管理画面は Stripe を読み込まないため、従来どおり締めたままにする。
        $scriptStripe = $isAdmin ? '' : ' '.self::STRIPE_SCRIPT;
        $frameSrc = $isAdmin ? "'none'" : self::STRIPE_FRAME;
        $connectStripe = $isAdmin ? '' : ' '.self::STRIPE_CONNECT;
        $imgStripe = $isAdmin ? '' : ' '.self::STRIPE_IMG;

        $script = $local
            ? "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173{$scriptStripe}"
            : "script-src 'self'{$scriptStripe}";

        $connect = $local
            ? "connect-src 'self' ws: http://localhost:5173{$connectStripe}"
            : "connect-src 'self'{$connectStripe}";

        // dev（Vite HMR）では CSS / webfont が http://localhost:5173 から配信されるため許可する。
        // 本番はビルド済み資産を 'self' から配信するので緩めない。
        $viteDev = $local ? ' http://localhost:5173' : '';

        return implode('; ', [
            "default-src 'self'",
            $script,
            "style-src 'self' 'unsafe-inline'{$viteDev}",
            "img-src 'self' data: blob:{$imgStripe}",
            "font-src 'self' data:{$viteDev}",
            $connect,
            "frame-src {$frameSrc}",
            // 他サイトからの埋め込みは常に禁止（Stripe の iframe とは無関係。緩めない）。
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
