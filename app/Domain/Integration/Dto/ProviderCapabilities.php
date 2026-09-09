<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

use App\Domain\Integration\Enum\ProviderCapability;
use App\Domain\Integration\Exception\UnsupportedProviderOperationException;

/**
 * Provider が対応する機能の集合（Value Object）。
 * Domain は Provider 固有名を知らず、この Capability だけを見て可否を判断する。
 */
final class ProviderCapabilities
{
    /** @var array<string, true> */
    private array $set = [];

    public function __construct(ProviderCapability ...$capabilities)
    {
        foreach ($capabilities as $capability) {
            $this->set[$capability->value] = true;
        }
    }

    public static function none(): self
    {
        return new self;
    }

    public function has(ProviderCapability $capability): bool
    {
        return isset($this->set[$capability->value]);
    }

    public function assert(ProviderCapability $capability): void
    {
        if (! $this->has($capability)) {
            throw new UnsupportedProviderOperationException(
                "この Provider は [{$capability->value}] に対応していません。",
            );
        }
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return array_keys($this->set);
    }
}
