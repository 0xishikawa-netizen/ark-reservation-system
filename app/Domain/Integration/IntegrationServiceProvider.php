<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Integration\Exception\ProviderConfigurationException;
use App\Domain\Integration\Provider\MockReservationStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 9 外部予約連携基盤の DI 束ね。
 */
final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ProviderResolver::class, function (Container $app): ProviderResolver {
            return new ProviderResolver($app->make(ProviderRegistry::class), $app);
        });

        // Mock の挙動注入ストアはプロセス内で共有（テストがシナリオを積む）。
        $this->app->singleton(MockReservationStore::class);
        $this->app->singleton(IntegrationContext::class);
    }

    /**
     * 設定異常は起動時に落とす（業務 transaction 中に初めて検出しない・F-18 / F-13）。
     */
    public function boot(): void
    {
        // テスト中は config を差し替えて検証するため boot バリデーションを行わない。
        if ($this->app->runningUnitTests()) {
            return;
        }

        $key = config('reservation_integration.active_provider');
        $key = is_string($key) ? trim($key) : '';

        if ($key !== '' && $key !== 'null') {
            $registry = $this->app->make(ProviderRegistry::class);

            if (! $registry->has($key)) {
                throw new ProviderConfigurationException(
                    "reservation_integration.active_provider [{$key}] は未登録です。config / env を確認してください。",
                );
            }

            // Phase 9 は authority=local（best-effort 非同期 outbound）のみ実装。
            // 非 local authority の「外部登録成功まで確定しない」ブロッキング経路は Phase 10。
            if (config('reservation.authority', 'local') !== 'local') {
                throw new ProviderConfigurationException(
                    'RESERVATION_AUTHORITY が local 以外です。Phase 9 の外部予約連携は authority=local のみ対応します（Phase 10）。',
                );
            }

            // production で mock を実動させない。
            if ($key === 'mock' && $this->app->environment('production')) {
                throw new ProviderConfigurationException('production で mock provider は使用できません。');
            }
        }
    }
}
