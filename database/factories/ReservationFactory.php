<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\SyncStatus;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Reservation $reservation): void {
            if ($reservation->ends_at !== null) {
                return;
            }

            $durationMinutes = $reservation->service()->value('duration_min');

            if (! is_numeric($durationMinutes)) {
                throw new LogicException('予約 Factory のサービス所要時間を取得できませんでした。');
            }

            $reservation->ends_at = CarbonImmutable::instance($reservation->starts_at)
                ->addMinutes((int) $durationMinutes);
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::instance(fake()->dateTimeBetween('+1 day', '+30 days'))
            ->startOfMinute();

        return [
            'customer_id' => Customer::factory(),
            'service_id' => Service::factory(),
            'staff_id' => Staff::factory(),
            'booth_id' => Booth::factory(),
            'starts_at' => $startsAt,
            'ends_at' => null,
            'source' => ReservationSource::Admin,
            'payment_method' => PaymentMethod::Onsite,
            'payment_status' => PaymentStatus::Unpaid,
            'payment_expires_at' => null,
            'status' => ReservationStatus::Confirmed,
            'attended_at' => null,
            'canceled_at' => null,
            'cancel_reason' => null,
            'external_provider' => null,
            'external_reservation_id' => null,
            'sync_status' => SyncStatus::NotRequired,
            'synced_at' => null,
            'sync_error' => null,
            'version' => 0,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
