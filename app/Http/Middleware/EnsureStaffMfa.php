<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * staff / manager / admin に有効な MFA 手段が 1 つも無い場合だけ setup へ誘導する（PLAN §12）。
 *
 * Phase 5.5 以前は `two_factor_confirmed_at` を直接見ていたため、
 * Passkey だけを登録したユーザーが TOTP setup 画面へ飛ばされてしまう問題があった。
 * 判定は必ず {@see MfaPolicy} を通す。
 */
class EnsureStaffMfa
{
    /** @var list<string> */
    private const EXCLUDED_ROUTES = [
        'admin.mfa.show',
        'admin.mfa.phone.start',
        'admin.mfa.phone.verify',
        // TOTP（移行期の代替手段）
        'admin.two-factor-setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        // Passkey（第一選択）。setup 中に自分自身でブロックしない。
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
        'passkey.confirm-options',
        'passkey.confirm',
        'password.confirm',
        'password.confirm.store',
        'logout',
    ];

    public function __construct(private readonly MfaPolicy $mfaPolicy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::EXCLUDED_ROUTES)) {
            return $next($request);
        }

        if ($this->mfaPolicy->needsSetup($request->user())) {
            return redirect()->route('admin.mfa.show');
        }

        return $next($request);
    }
}
