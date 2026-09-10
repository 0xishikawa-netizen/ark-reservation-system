<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 業務ロール（staff / manager / admin）による TOTP 無効化を止める（自己ロックアウト対策）。
 *
 * Phase 9.6 で Passkey を撤去し MFA 手段は TOTP 1 本になった。
 * MFA 必須ユーザーが Fortify の `two-factor.disable` を叩くと MFA ゼロになり、
 * 以後 `/admin` へ入るたび setup へ戻される。端末変更は「無効化」ではなく
 * 認証アプリ側の再登録＋新しい QR で行う運用にする。
 *
 * Fortify がルートを登録するため、ルート定義ではなく web グループの
 * middleware で横断的に防ぐ。
 */
class PreventStaffTotpDisable
{
    public function __construct(private readonly MfaPolicy $mfaPolicy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('two-factor.disable')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $this->mfaPolicy->isRequiredFor($user)) {
            throw ValidationException::withMessages([
                'two_factor' => '業務用アカウントは二段階認証を無効化できません。'
                    .'端末を変更する場合は、認証アプリで新しい QR コードを読み込んで再設定してください。',
            ]);
        }

        return $next($request);
    }
}
