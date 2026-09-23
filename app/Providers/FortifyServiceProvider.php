<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatableUnlessTrustedDevice;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\TrustedDeviceAwareTwoFactorLoginResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TwoFactorLoginResponseContract::class, TrustedDeviceAwareTwoFactorLoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->configureAuthentication();
        $this->configureLoginPipeline();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * 標準の login パイプラインから RedirectIfTwoFactorAuthenticatable だけを
     * 「信頼済み端末」対応版に差し替える（他は vendor のデフォルトと同じ）。
     */
    private function configureLoginPipeline(): void
    {
        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            Features::enabled(Features::twoFactorAuthentication())
                ? RedirectIfTwoFactorAuthenticatableUnlessTrustedDevice::class
                : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * 通常のメール／パスワードログインに、ログイン無効化（is_active）のチェックを差し込む。
     * 「認証情報が正しいか」と「無効化されていないか」を区別できるよう、
     * 別メッセージで弾く（総当たり耐性のため email/password 自体はここでは検証エラーにしない）。
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->input('email'))->first();

            if ($user === null || $user->password === null
                || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->is_active === false) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('messages.auth.account_disabled'),
                ]);
            }

            return $user;
        });
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'status' => $this->statusMessage(),
        ]));
        Fortify::registerView(fn () => Inertia::render('Auth/Register'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => $this->statusMessage(),
        ]));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail', [
            'status' => $this->statusMessage(),
        ]));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge', [
            'trustedDeviceTtlDays' => (int) config('mfa.trusted_device.ttl_days', 365),
        ]));
    }

    /**
     * Fortify は session('status') に 'verification-link-sent' のような状態コードを入れるため、
     * そのまま画面に出すと英語のコードが表示される。lang/ja.json で日本語の文言に変換して渡す。
     */
    private function statusMessage(): ?string
    {
        $status = session('status');

        return is_string($status) && $status !== '' ? __($status) : null;
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by(
            (string) ($request->session()->get('login.id') ?? $request->ip()),
        ));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));

        // 再認証（POST /user/confirm-password）の総当たり対策。ユーザー ID + IP で絞る。
        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(6)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));

        // Google OAuth の redirect / callback。IP 単位で緩めに制限（正規利用を阻害しない）。
        RateLimiter::for('google-oauth', fn (Request $request) => [
            Limit::perMinute(15)->by((string) $request->ip()),
            Limit::perMinute(30)->by((string) ($request->session()->getId() ?: $request->ip())),
        ]);

        RateLimiter::for('reserve', fn (Request $request) => Limit::perMinute(10)->by(
            (string) ($request->user()?->id ?? $request->ip()),
        ));

        RateLimiter::for('guest-reserve', fn (Request $request) => [
            Limit::perMinute(5)->by((string) $request->ip()),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('guest-lookup', fn (Request $request) => [
            Limit::perMinute(5)->by((string) $request->ip()),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);
    }
}
