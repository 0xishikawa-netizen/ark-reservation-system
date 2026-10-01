<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use Carbon\CarbonImmutable;

final readonly class ReservationInput
{
    public function __construct(
        public int $customerId,
        public int $serviceId,
        public ?int $staffId,
        public ?int $boothId,
        public CarbonImmutable $startsAt,
        public ReservationSource $source,
        public ?int $actorUserId,
        public ?string $notes,
        public bool $adminContext,
        public PaymentMethod $paymentMethod = PaymentMethod::Onsite,
        public bool $isStaffRequested = false,
        /** 担当スタッフの性別希望（male / female）。希望なしは null。 */
        public ?string $staffGenderPreference = null,
        /** 施術後に確保する余白（分）。ends_at はこれを含めて算出する。 */
        public int $bufferMin = 0,
    ) {}
}
