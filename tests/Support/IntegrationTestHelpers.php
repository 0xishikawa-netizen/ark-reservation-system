<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Provider\MockReservationStore;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use Carbon\CarbonImmutable;

trait IntegrationTestHelpers
{
    protected function useMockProvider(): MockReservationStore
    {
        config()->set('reservation_integration.active_provider', 'mock');
        config()->set('reservation_integration.outbox.enabled', true);
        config()->set('reservation_integration.inbound.enabled', true);

        $store = app(MockReservationStore::class);
        $store->reset();

        return $store;
    }

    /** @return array{Customer, Service, Staff} */
    protected function reservationMasters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }

    protected function externalData(
        string $externalId,
        Service $service,
        Customer $customer,
        ?Staff $staff = null,
        string $startsAt = '2026-10-01 10:00:00',
        string $endsAt = '2026-10-01 11:00:00',
        string $status = 'confirmed',
        bool $canceled = false,
        ?string $updatedAt = null,
    ): ExternalReservationData {
        return new ExternalReservationData(
            provider: 'mock',
            externalReservationId: $externalId,
            startsAt: CarbonImmutable::parse($startsAt),
            endsAt: CarbonImmutable::parse($endsAt),
            externalStatus: $status,
            isCanceled: $canceled,
            externalCustomerId: (string) $customer->user_id,
            serviceRef: (string) $service->id,
            staffRef: $staff === null ? null : (string) $staff->user_id,
            externalUpdatedAt: $updatedAt === null ? null : CarbonImmutable::parse($updatedAt),
        );
    }

    protected function arkReservation(Customer $customer, Service $service, Staff $staff, string $startsAt = '2026-10-01 10:00:00'): Reservation
    {
        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addHour(),
            'status' => 'confirmed',
            'source' => 'EXTERNAL',
            'version' => 0,
        ]);
    }
}
