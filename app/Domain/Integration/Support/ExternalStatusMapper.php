<?php

declare(strict_types=1);

namespace App\Domain\Integration\Support;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Enums\Reservation\ReservationStatus;

/**
 * 外部 status 文字列 → ARK ReservationStatus の明示写像。
 * 未知 status は勝手に正常扱いせず null（呼び出し側で CONFLICT: UNSUPPORTED_STATE）。
 * provider 別の対応表は Phase 10 でここに追加する（Domain へは漏らさない）。
 */
final class ExternalStatusMapper
{
    /** provider 非依存の共通語彙（Mock はこれを返す）。 */
    private const COMMON = [
        'confirmed' => ReservationStatus::Confirmed,
        'booked' => ReservationStatus::Confirmed,
        'reserved' => ReservationStatus::Confirmed,
        'completed' => ReservationStatus::Completed,
        'attended' => ReservationStatus::Completed,
        'no_show' => ReservationStatus::NoShow,
        'noshow' => ReservationStatus::NoShow,
        'canceled' => ReservationStatus::Canceled,
        'cancelled' => ReservationStatus::Canceled,
    ];

    public function map(ExternalReservationData $data): ?ReservationStatus
    {
        if ($data->isCanceled) {
            return ReservationStatus::Canceled;
        }

        $key = strtolower(trim($data->externalStatus));

        return self::COMMON[$key] ?? self::providerSpecific($data->provider, $key);
    }

    private function providerSpecific(string $provider, string $status): ?ReservationStatus
    {
        // Phase 10: match ($provider) { 'peak_manager' => [...][$status] ?? null, ... }
        return null;
    }
}
