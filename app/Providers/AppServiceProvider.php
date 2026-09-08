<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\Sms\FakeSmsSender;
use App\Domain\Auth\Sms\LogSmsSender;
use App\Domain\Auth\Sms\SmsSender;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\Gateway\MembershipStripeGateway;
use App\Domain\Membership\Gateway\StripeApiMembershipGateway;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeApiGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Listeners\AuditAuthEvents;
use App\Listeners\AuditPasskeyEvents;
use App\Models\Customer;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Cashier は「Stripe 課金契約の記録」に限定して使う（業務状態は memberships が SoR）。
        // - webhook 入口は Phase 5 の POST /stripe/webhook 1 つに統合する（Cashier の /stripe/webhook は登録しない）。
        // - migration は publish 済みのものだけが走る（Cashier v16 は vendor migration を loadMigrationsFrom しない）。
        // - billable は App\Models\Customer。
        Cashier::ignoreRoutes();
        Cashier::useCustomerModel(Customer::class);

        $this->app->scoped(Settings::class);
        $this->app->alias(Settings::class, 'settings');

        if ($this->app->environment('testing')) {
            $this->app->singleton(FakeStripeGateway::class);
            $this->app->bind(
                StripeGateway::class,
                static fn ($app): StripeGateway => $app->make(FakeStripeGateway::class),
            );

            $this->app->singleton(FakeMembershipStripeGateway::class);
            $this->app->bind(
                MembershipStripeGateway::class,
                static fn ($app): MembershipStripeGateway => $app->make(FakeMembershipStripeGateway::class),
            );
        } else {
            $this->app->singleton(StripeGateway::class, StripeApiGateway::class);
            $this->app->singleton(MembershipStripeGateway::class, StripeApiMembershipGateway::class);
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
