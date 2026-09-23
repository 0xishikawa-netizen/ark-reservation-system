<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\AvailabilityService;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Models\Booth;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = CarbonImmutable::parse('2026-10-01');

        $settings = app(Settings::class);
        $settings->set('reservation.slot_minutes', 15, 'int');
        $settings->set('business_hours.open', '10:00');
        $settings->set('business_hours.close', '22:00');
    }

    public function test_requires_staff_service_has_no_candidates_without_a_shift(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();

        $this->assertSame([], $this->openStartTimes($service, $staff));
    }

    public function test_shift_exposes_every_boundary_that_can_contain_the_full_duration(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff, '10:00', '14:00');

        $slots = $this->openStartTimes($service, $staff);

        $this->assertSame([
            '2026-10-01 10:00:00',
            '2026-10-01 10:15:00',
            '2026-10-01 10:30:00',
            '2026-10-01 10:45:00',
            '2026-10-01 11:00:00',
            '2026-10-01 11:15:00',
            '2026-10-01 11:30:00',
            '2026-10-01 11:45:00',
            '2026-10-01 12:00:00',
            '2026-10-01 12:15:00',
            '2026-10-01 12:30:00',
            '2026-10-01 12:45:00',
            '2026-10-01 13:00:00',
        ], array_column($slots, 'starts_at'));
        $this->assertSame('2026-10-01 11:00:00', $slots[0]['ends_at']);
        $this->assertSame([], $slots[0]['available_staff_ids']);
    }

    public function test_overlapping_starts_are_removed_for_an_occupied_staff(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff, '10:00', '14:00');
        $this->occupy(
            $service,
            ResourceType::Staff,
            (int) $staff->user_id,
            '2026-10-01 11:00:00',
            '2026-10-01 12:00:00',
        );

        $starts = array_column($this->openStartTimes($service, $staff), 'starts_at');

        $this->assertContains('2026-10-01 10:00:00', $starts);
        $this->assertContains('2026-10-01 12:00:00', $starts);

        foreach ([
            '2026-10-01 10:15:00',
            '2026-10-01 10:30:00',
            '2026-10-01 10:45:00',
            '2026-10-01 11:00:00',
            '2026-10-01 11:15:00',
            '2026-10-01 11:30:00',
            '2026-10-01 11:45:00',
        ] as $overlappingStart) {
            $this->assertNotContains($overlappingStart, $starts);
        }
    }

    public function test_start_adjacent_to_an_existing_reservation_remains_available(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff, '10:00', '14:00');
        $this->occupy(
            $service,
            ResourceType::Staff,
            (int) $staff->user_id,
            '2026-10-01 10:00:00',
            '2026-10-01 11:00:00',
        );

        $starts = array_column($this->openStartTimes($service, $staff), 'starts_at');

        $this->assertContains('2026-10-01 11:00:00', $starts);
    }

    public function test_specified_staff_not_assigned_to_the_service_has_no_candidates(): void
    {
        $service = Service::factory()->create(['duration_min' => 60, 'requires_staff' => true]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $this->shift($staff, '10:00', '14:00');

        $this->assertSame([], $this->openStartTimes($service, $staff));
    }

    public function test_specified_unbookable_staff_has_no_candidates(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff(isBookable: false);
        $this->shift($staff, '10:00', '14:00');

        $this->assertSame([], $this->openStartTimes($service, $staff));
    }

    public function test_unassigned_request_lists_only_available_capable_staff(): void
    {
        [$service, $staffA] = $this->bookableServiceAndStaff();
        $staffB = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staffB->user_id);
        $this->shift($staffA, '10:00', '14:00');
        $this->shift($staffB, '10:00', '14:00');
        $this->occupy(
            $service,
            ResourceType::Staff,
            (int) $staffA->user_id,
            '2026-10-01 11:00:00',
            '2026-10-01 12:00:00',
        );

        $slot = collect($this->openStartTimes($service))
            ->firstWhere('starts_at', '2026-10-01 11:00:00');

        $this->assertIsArray($slot);
        $this->assertSame([(int) $staffB->user_id], $slot['available_staff_ids']);
        $this->assertNotContains((int) $staffA->user_id, $slot['available_staff_ids']);
    }

    public function test_occupied_booth_removes_overlapping_starts_but_keeps_adjacent_start(): void
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => false,
            'is_active' => true,
        ]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $this->occupy(
            $service,
            ResourceType::Booth,
            (int) $booth->id,
            '2026-10-01 10:00:00',
            '2026-10-01 11:00:00',
        );

        $starts = array_column(
            $this->openStartTimes($service, booth: $booth),
            'starts_at',
        );

        foreach ([
            '2026-10-01 10:00:00',
            '2026-10-01 10:15:00',
            '2026-10-01 10:30:00',
            '2026-10-01 10:45:00',
        ] as $overlappingStart) {
            $this->assertNotContains($overlappingStart, $starts);
        }

        $this->assertContains('2026-10-01 11:00:00', $starts);
    }

    public function test_with_booths_excludes_times_when_every_booth_is_taken_and_lists_free_booths(): void
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => false,
            'is_active' => true,
        ]);
        $boothA = Booth::factory()->create(['is_active' => true, 'sort_order' => 1]);
        $boothB = Booth::factory()->create(['is_active' => true, 'sort_order' => 2]);
        // 10:00-11:00 は両ブースとも埋まり、11:00-12:00 はAだけ埋まる。
        $this->occupy($service, ResourceType::Booth, (int) $boothA->id, '2026-10-01 10:00:00', '2026-10-01 12:00:00');
        $this->occupy($service, ResourceType::Booth, (int) $boothB->id, '2026-10-01 10:00:00', '2026-10-01 11:00:00');

        $slots = app(AvailabilityService::class)->openStartTimes(
            (int) $service->id,
            null,
            null,
            $this->date,
            withBooths: true,
        );
        $byStart = array_column($slots, 'available_booth_ids', 'starts_at');

        $this->assertArrayNotHasKey('2026-10-01 10:00:00', $byStart);
        $this->assertArrayNotHasKey('2026-10-01 10:45:00', $byStart);
        $this->assertSame([(int) $boothB->id], $byStart['2026-10-01 11:00:00']);
        $this->assertSame([(int) $boothA->id, (int) $boothB->id], $byStart['2026-10-01 12:00:00']);

        // 従来の呼び出し（withBooths なし）はブースを考慮しない。
        $legacy = array_column($this->openStartTimes($service), 'starts_at');
        $this->assertContains('2026-10-01 10:00:00', $legacy);
    }

    public function test_with_booths_requires_at_least_one_active_booth(): void
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => false,
            'is_active' => true,
        ]);

        $this->assertSame([], app(AvailabilityService::class)->openStartTimes(
            (int) $service->id,
            null,
            null,
            $this->date,
            withBooths: true,
        ));
        $this->assertNotEmpty($this->openStartTimes($service));
    }

    public function test_shift_shorter_than_service_duration_has_no_candidates(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff(durationMinutes: 90);
        $this->shift($staff, '10:00', '11:00');

        $this->assertSame([], $this->openStartTimes($service, $staff));
    }

    public function test_slots_of_a_non_active_reservation_do_not_block_availability(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff, '10:00', '14:00');
        $this->occupy(
            $service,
            ResourceType::Staff,
            (int) $staff->user_id,
            '2026-10-01 10:00:00',
            '2026-10-01 11:00:00',
            ReservationStatus::Completed,
        );

        $starts = array_column($this->openStartTimes($service, $staff), 'starts_at');

        $this->assertContains('2026-10-01 10:00:00', $starts);
    }

    public function test_inactive_service_or_booth_has_no_candidates(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff, '10:00', '14:00');
        $service->update(['is_active' => false]);

        $this->assertSame([], $this->openStartTimes($service, $staff));

        $service->update(['is_active' => true, 'requires_staff' => false]);
        $booth = Booth::factory()->create(['is_active' => false]);

        $this->assertSame([], $this->openStartTimes($service, booth: $booth));
    }

    public function test_business_hours_are_read_from_settings(): void
    {
        $service = Service::factory()->create([
            'duration_min' => 30,
            'requires_staff' => false,
            'is_active' => true,
        ]);
        $settings = app(Settings::class);
        $settings->set('business_hours.open', '11:00');
        $settings->set('business_hours.close', '12:00');

        $slots = $this->openStartTimes($service);

        $this->assertSame([
            '2026-10-01 11:00:00',
            '2026-10-01 11:15:00',
            '2026-10-01 11:30:00',
        ], array_column($slots, 'starts_at'));
        $this->assertSame([[], [], []], array_column($slots, 'available_staff_ids'));
    }

    /** @return array{Service, Staff} */
    private function bookableServiceAndStaff(
        int $durationMinutes = 60,
        bool $isBookable = true,
    ): array {
        $service = Service::factory()->create([
            'duration_min' => $durationMinutes,
            'requires_staff' => true,
            'is_active' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => $isBookable]);
        $service->staff()->attach($staff->user_id);

        return [$service, $staff];
    }

    private function shift(Staff $staff, string $start, string $end): void
    {
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => $this->date->toDateString(),
            'start_at' => $start,
            'end_at' => $end,
        ]);
    }

    private function occupy(
        Service $service,
        ResourceType $resourceType,
        int $resourceId,
        string $startsAt,
        string $endsAt,
        ReservationStatus $status = ReservationStatus::Confirmed,
    ): void {
        $reservation = Reservation::factory()->create([
            'service_id' => $service->id,
            'staff_id' => $resourceType === ResourceType::Staff ? $resourceId : null,
            'booth_id' => $resourceType === ResourceType::Booth ? $resourceId : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
        ]);

        $rows = [];

        foreach (SlotKey::fromSettings()->occupiedSlots(
            CarbonImmutable::parse($startsAt),
            CarbonImmutable::parse($endsAt),
            false,
        ) as $slot) {
            $rows[] = [
                'resource_type' => $resourceType->value,
                'resource_id' => $resourceId,
                'slot_start' => $slot,
                'reservation_id' => $reservation->id,
            ];
        }

        ReservationResourceSlot::query()->insert($rows);
    }

    /**
     * @return list<array{starts_at: string, ends_at: string, available_staff_ids: list<int>}>
     */
    private function openStartTimes(
        Service $service,
        ?Staff $staff = null,
        ?Booth $booth = null,
    ): array {
        return app(AvailabilityService::class)->openStartTimes(
            (int) $service->id,
            $staff === null ? null : (int) $staff->user_id,
            $booth === null ? null : (int) $booth->id,
            $this->date,
        );
    }
}
