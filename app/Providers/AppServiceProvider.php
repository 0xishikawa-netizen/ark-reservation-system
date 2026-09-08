<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeApiGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Listeners\AuditAuthEvents;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::assertNoStripeLiveKeys();

        Event::subscribe(AuditAuthEvents::class);
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
