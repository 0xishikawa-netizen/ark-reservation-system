<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

/**
 * Provider の疎通状態。secret / credential / raw 応答は含めない。
 */
final readonly class ProviderHealth
{
    private function __construct(
        public string $state,       // ok | needs_attention | unconfigured
        public ?string $detail,     // 安全な短い説明のみ
    ) {}

    public static function ok(?string $detail = null): self
    {
        return new self('ok', $detail);
    }

    public static function needsAttention(string $detail): self
    {
        return new self('needs_attention', $detail);
    }

    public static function unconfigured(string $detail = '未設定です。'): self
    {
        return new self('unconfigured', $detail);
    }
}
