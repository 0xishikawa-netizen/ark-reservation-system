<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Integration\Exception\ProviderConfigurationException;

/**
 * config('reservation_integration.providers') の key → class 対応表。
 * unknown key は fail-closed（silent fallback しない）。
 */
final class ProviderRegistry
{
    /** @var array<string, string> key => provider class */
    private array $map;

    /**
     * @param  array<string, mixed>|null  $providers
     */
    public function __construct(?array $providers = null)
    {
        $providers ??= config('reservation_integration.providers', []);
        $map = [];

        foreach ((array) $providers as $key => $definition) {
            $class = is_array($definition) ? ($definition['class'] ?? null) : null;

            if (is_string($key) && is_string($class) && $class !== '') {
                $map[$key] = $class;
            }
        }

        $this->map = $map;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->map);
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    /** @return class-string */
    public function classFor(string $key): string
    {
        if (! isset($this->map[$key])) {
            throw new ProviderConfigurationException("未知の予約 Provider [{$key}] です。");
        }

        $class = $this->map[$key];

        if (! class_exists($class)) {
            throw new ProviderConfigurationException("予約 Provider [{$key}] の実装クラスが見つかりません。");
        }

        /** @var class-string */
        return $class;
    }
}
