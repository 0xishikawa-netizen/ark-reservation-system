<?php

declare(strict_types=1);

namespace App\Actions\Reservation;

use App\Exceptions\Reservation\StaleReservationException;
use App\Models\Reservation;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class UpdateReservationNotes
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Reservation $reservation,
        ?string $notes,
        int $expectedVersion,
        ?Authenticatable $actor,
    ): Reservation {
        $reservation = DB::transaction(function () use (
            $reservation,
            $notes,
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
                'notes' => $notes,
                'version' => $locked->version + 1,
            ])->save();

            return $locked;
        });

        $this->auditLogger->log(
            'reservation.notes_updated',
            $reservation,
            "予約備考更新 #{$reservation->id}",
            $actor,
        );

        return $reservation;
    }
}
