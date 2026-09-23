<?php

declare(strict_types=1);

namespace App\Actions\Reservation;

use App\Exceptions\Reservation\StaleReservationException;
use App\Models\Reservation;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * 予約の日時・担当を変えず「指名」フラグだけを更新する（§5-2）。
 * スケジュール変更を伴わない軽微な更新のため reschedule() は使わず、
 * notes 更新（UpdateReservationNotes）と同じ薄いパターンにする。
 */
final class UpdateReservationNomination
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Reservation $reservation,
        bool $isStaffRequested,
        int $expectedVersion,
        ?Authenticatable $actor,
    ): Reservation {
        $reservation = DB::transaction(function () use (
            $reservation,
            $isStaffRequested,
            $expectedVersion,
        ): Reservation {
            $locked = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->version !== $expectedVersion) {
                throw new StaleReservationException;
            }

            $locked->forceFill([
                // 担当スタッフが未割当なら「指名」は成立しない。
                'is_staff_requested' => $locked->staff_id !== null && $isStaffRequested,
                'version' => $locked->version + 1,
            ])->save();

            return $locked;
        });

        $this->auditLogger->log(
            'reservation.nomination_updated',
            $reservation,
            "予約指名フラグ更新 #{$reservation->id} ".($reservation->is_staff_requested ? '指名あり' : '指名なし'),
            $actor,
        );

        return $reservation;
    }
}
