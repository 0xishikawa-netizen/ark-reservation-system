<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\Sms\FakeSmsSender;
use App\Domain\Auth\Sms\LogSmsSender;
use App\Domain\Auth\Sms\SmsSender;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeApiGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Listeners\AuditAuthEvents;
use App\Listeners\AuditPasskeyEvents;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Settings::class);
        $this->app->alias(Settings::class, 'settings');

        if ($this->app->environment('testing')) {
            $this->app->singleton(FakeStripeGateway::class);
            $this->app->bind(
                StripeGateway::class,
                static fn ($app): StripeGateway => $app->make(FakeStripeGateway::class),
            );
        } else {
            $this->app->singleton(StripeGateway::class, StripeApiGateway::class);
        }

        $this->registerSmsSender();
    }

    /**
     * SMS provider は未契約のため log / fake のみ。
     * 実 provider を追加する際もここだけを変更する（Controller / Service は触らない）。
     */
    private function registerSmsSender(): void
    {
        if ($this->app->environment('testing')) {
            $this->app->singleton(FakeSmsSender::class);
            $this->app->bind(SmsSender::class, static fn ($app): SmsSender => $app->make(FakeSmsSender::class));

            return;
        }

        $driver = (string) config('mfa.sms.driver', 'log');

        $this->app->singleton(SmsSender::class, match ($driver) {
            'log' => LogSmsSender::class,
            default => throw new RuntimeException(
                "未対応の MFA SMS ドライバです: [{$driver}]。実 provider は未契約のため 'log' のみ利用できます。",
            ),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::assertNoStripeLiveKeys();

        Event::subscribe(AuditAuthEvents::class);

        // Passkey の登録 / 削除 / 認証を監査する（credential 本体は記録しない）。
        Event::listen(\Laravel\Passkeys\Events\PasskeyRegistered::class, [AuditPasskeyEvents::class, 'handleRegistered']);
        Event::listen(\Laravel\Passkeys\Events\PasskeyDeleted::class, [AuditPasskeyEvents::class, 'handleDeleted']);
        Event::listen(\Laravel\Passkeys\Events\PasskeyVerified::class, [AuditPasskeyEvents::class, 'handleVerified']);
    }

    public static function assertNoStripeLiveKeys(): void
    {
        $blockedEnvironments = (array) config('stripe.block_live_keys_in', ['local', 'testing']);

        if (! in_array(app()->environment(), $blockedEnvironments, true)) {
            return;
        }

        if (Str::startsWith((string) config('stripe.key'), 'pk_live_')
            || Str::startsWith((string) config('stripe.secret'), 'sk_live_')) {
            throw new RuntimeException('Stripe Live キーは local/testing で使用できません。');
        }
    }
}
