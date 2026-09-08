<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 最後の MFA 手段の削除を拒否する（自己ロックアウト対策。Phase 5.5 の最重要要件）。
 *
 * Passkey 削除ルートは `laravel/passkeys` が登録するため、
 * ルート定義に手を入れず web グループの middleware で横断的に止める。
 *
 * 単一 admin が Passkey を消して MFA ゼロになると、
 * Recovery Code も失っていた場合に復旧不能になる。
 */
class PreventLastMfaRemoval
{
    public function __construct(private readonly MfaPolicy $mfaPolicy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passkey.destroy')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || ! $this->mfaPolicy->isRequiredFor($user)) {
            return $next($request);
        }

        // 他人の Passkey を削除しようとしている場合はここで判断しない。
        // 所有権の検証（403）はパッケージ側の認可に委ねる。
        $passkey = $request->route('passkey');

        if ($passkey !== null
            && (string) $passkey->getAttribute('user_id') !== (string) $user->getAuthIdentifier()) {
            return $next($request);
        }

        if ($this->mfaPolicy->wouldRemoveLastMethod($user)) {
            throw ValidationException::withMessages([
                'passkey' => '最後の多要素認証手段は削除できません。'
                    .'先に別の Passkey を登録するか、認証アプリ（TOTP）を設定してください。',
            ]);
        }

        return $next($request);
    }
}
