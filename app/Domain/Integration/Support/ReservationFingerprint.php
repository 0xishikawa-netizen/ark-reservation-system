<?php

declare(strict_types=1);

namespace App\Domain\Integration\Support;

use App\Models\Reservation;

/**
 * ARK 予約の同期比較用 fingerprint。ExternalReservationData::fingerprint() と対。
 * starts_at / service / staff / canonical status で決まる（ends_at は含めない・F-08）。
 */
final class ReservationFingerprint
{
    public static function forReservation(Reservation $reservation): string
    {
        $status = $reservation->status->value;
        $canceledLike = in_array($status, ['canceled', 'expired'], true);

        return hash('sha256', implode('|', [
            $reservation->starts_at?->utc()->format('YmdHis') ?? '',
            (string) $reservation->service_id,
            (string) ($reservation->staff_id ?? ''),
            $canceledLike ? 'canceled' : $status,
        ]));
    }
}
