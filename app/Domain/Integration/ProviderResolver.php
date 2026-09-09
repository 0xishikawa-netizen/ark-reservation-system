<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Integration\Contracts\ReservationProvider;
use App\Domain\Integration\Exception\ProviderConfigurationException;
use Illuminate\Contracts\Container\Container;

/**
 * 有効な Provider を 1 つ解決する。将来 multi-provider へ拡張できるよう key 指定も受け付ける
 * （Phase 9 では active_provider 1 つのみ実運用）。
 */
final class ProviderResolver
{
    /** @var array<string, ReservationProvider> */
    private array $instances = [];

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly Container $container,
    ) {}

    public function activeKey(): ?string
    {
        $key = config('reservation_integration.active_provider');
        $key = is_string($key) ? trim($key) : '';

        if ($key === '' || $key === 'null') {
            return null;
        }

        if (! $this->registry->has($key)) {
            // config typo を silent fallback しない。
            throw new ProviderConfigurationException("active_provider [{$key}] は未登録です。config を確認してください。");
        }

        return $key;
    }

    public function hasActive(): bool
    {
        return $this->activeKey() !== null;
    }

    /** 有効 Provider。未設定なら例外（呼ぶ側は hasActive() で確認する）。 */
    public function active(): ReservationProvider
    {
        $key = $this->activeKey();

        if ($key === null) {
            throw new ProviderConfigurationException('有効な予約 Provider が設定されていません。');
        }

        return $this->resolve($key);
    }

    public function resolve(string $key): ReservationProvider
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $class = $this->registry->classFor($key);
        $provider = $this->container->make($class);

        if (! $provider instanceof ReservationProvider) {
            throw new ProviderConfigurationException("Provider [{$key}] は ReservationProvider を実装していません。");
        }

        return $this->instances[$key] = $provider;
    }

    /** @return list<string> */
    public function knownKeys(): array
    {
        return $this->registry->keys();
    }
}
