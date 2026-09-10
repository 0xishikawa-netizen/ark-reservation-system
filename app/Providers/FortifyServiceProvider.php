<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]));
        Fortify::registerView(fn () => Inertia::render('Auth/Register'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));
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
    }
}
