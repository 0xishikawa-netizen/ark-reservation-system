<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Auth\TrustedDeviceService;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify 標準の RedirectIfTwoFactorAuthenticatable を差し替え、
 * 「信頼済み端末」（{@see TrustedDeviceService}）の場合は TOTP チャレンジを省略する。
 *
 * ロジックは vendor 実装を踏襲しつつ信頼済み端末判定だけを追加している。
 * 継承ではなく複製にしているのは、親クラスを呼ぶと資格情報の検証
 * （validateCredentials）が二重に走り、失敗イベント／レート制限のカウントが
 * 二重計上されてしまうため。
 */
final class RedirectIfTwoFactorAuthenticatableUnlessTrustedDevice implements RedirectsIfTwoFactorAuthenticatable
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly LoginRateLimiter $limiter,
        private readonly TrustedDeviceService $trustedDevices,
    ) {}

    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        $hasConfirmedTotp = optional($user)->two_factor_secret
            && ! is_null(optional($user)->two_factor_confirmed_at)
            && in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user));

        if (! $hasConfirmedTotp) {
            return $next($request);
        }

        if ($user instanceof User && $this->trustedDevices->isTrusted($user, $request)) {
            return $next($request);
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }

    /**
     * @param  Request  $request
     * @return mixed
     */
    private function validateCredentials($request)
    {
        if (Fortify::$authenticateUsingCallback) {
            return tap(call_user_func(Fortify::$authenticateUsingCallback, $request), function ($user) use ($request): void {
                if (! $user) {
                    $this->fireFailedEvent($request);
                    $this->throwFailedAuthenticationException($request);
                }
            });
        }

        $provider = $this->guard->getProvider();

        return tap($provider->retrieveByCredentials($request->only(Fortify::username(), 'password')), function ($user) use ($provider, $request): void {
            if (! $user || ! $provider->validateCredentials($user, ['password' => $request->password])) {
                $this->fireFailedEvent($request, $user);
                $this->throwFailedAuthenticationException($request);
            }

            if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                $provider->rehashPasswordIfRequired($user, ['password' => $request->password]);
            }
        });
    }

    /**
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    private function throwFailedAuthenticationException($request): never
    {
        $this->limiter->increment($request);

        throw ValidationException::withMessages([
            Fortify::username() => [trans('auth.failed')],
        ]);
    }

    /**
     * @param  Request  $request
     * @param  mixed  $user
     */
    private function fireFailedEvent($request, $user = null): void
    {
        event(new Failed($this->guard?->name ?? config('fortify.guard'), $user, [
            Fortify::username() => $request->{Fortify::username()},
            'password' => $request->password,
        ]));
    }

    /**
     * @param  Request  $request
     * @param  mixed  $user
     * @return Response
     */
    private function twoFactorChallengeResponse($request, $user)
    {
        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $request->boolean('remember'),
        ]);

        TwoFactorAuthenticationChallenged::dispatch($user);

        return $request->wantsJson()
            ? response()->json(['two_factor' => true])
            : redirect()->route('two-factor.login');
    }
}
