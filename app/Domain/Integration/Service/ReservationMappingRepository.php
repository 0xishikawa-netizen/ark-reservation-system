<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Exception\ProviderPermanentException;
use App\Models\ReservationProviderMapping;
use Illuminate\Database\QueryException;

/**
 * provider + external_reservation_id / provider + reservation_id の UNIQUE を最終防衛線として、
 * 同時 import / retry でも mapping 1 件に収束させる。
 */
final class ReservationMappingRepository
{
    public function findByExternal(string $provider, string $externalId): ?ReservationProviderMapping
    {
        return ReservationProviderMapping::query()
            ->where('provider', $provider)
            ->where('external_reservation_id', $externalId)
            ->first();
    }

    public function findByReservation(string $provider, int $reservationId): ?ReservationProviderMapping
    {
        return ReservationProviderMapping::query()
            ->where('provider', $provider)
            ->where('reservation_id', $reservationId)
            ->first();
    }

    /**
     * 外部予約に対応する mapping を作る/取得する。UNIQUE violation は 500 にせず既存へ収束。
     */
    public function firstOrCreateForExternal(
        string $provider,
        string $externalId,
        int $reservationId,
        ?string $externalCustomerId = null,
    ): ReservationProviderMapping {
        $existing = $this->findByExternal($provider, $externalId);

        if ($existing !== null) {
            if ((int) $existing->reservation_id !== $reservationId) {
                // 並行処理の敗者。呼び出し側 transaction をロールバックさせ、二重予約を作らない。
                throw new ProviderPermanentException(
                    "external {$provider}:{$externalId} は既に別予約に mapping 済みです。",
                );
            }

            return $existing;
        }

        try {
            return ReservationProviderMapping::query()->create([
                'provider' => $provider,
                'reservation_id' => $reservationId,
                'external_reservation_id' => $externalId,
                'external_customer_id' => $externalCustomerId,
                'sync_status' => 'in_sync',
                'last_seen_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            // 並列 import / retry。既存へ収束（provider+external か provider+reservation のどちらかで衝突）。
            return $this->findByExternal($provider, $externalId)
                ?? $this->findByReservation($provider, $reservationId)
                ?? throw $exception;
        }
    }
}
