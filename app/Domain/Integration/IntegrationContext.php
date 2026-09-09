<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use Closure;

/**
 * inbound 適用中フラグ（container singleton）。
 * inbound で ReservationService を呼んだ変更を同じ Provider へ折り返し送信しないための因果情報。
 * 永続 source に頼らず、この明示コンテキストで判定する（F-06）。
 */
final class IntegrationContext
{
    private bool $applyingInbound = false;

    public function isApplyingInbound(): bool
    {
        return $this->applyingInbound;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function applyingInbound(Closure $fn): mixed
    {
        $previous = $this->applyingInbound;
        $this->applyingInbound = true;

        try {
            return $fn();
        } finally {
            $this->applyingInbound = $previous;
        }
    }
}
