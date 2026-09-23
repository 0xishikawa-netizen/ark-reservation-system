<?php

declare(strict_types=1);

namespace Tests\Unit\Reservation;

use App\Exceptions\NonBoundaryStartException;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SlotKeyTest extends TestCase
{
    use RefreshDatabase;

    private SlotKey $slotKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slotKey = new SlotKey(15);
    }

    public function test_customer_hour_occupies_four_slots(): void
    {
        $slots = $this->slotKey->occupiedSlots($this->time('10:00'), $this->time('11:00'));

        $this->assertSame(['10:00', '10:15', '10:30', '10:45'], $this->formatSlots($slots));
        $this->assertContainsOnlyInstancesOf(CarbonImmutable::class, $slots);
    }

    public function test_customer_partial_ending_slot_is_occupied(): void
    {
        $slots = $this->slotKey->occupiedSlots($this->time('10:00'), $this->time('10:50'));

        $this->assertSame(['10:00', '10:15', '10:30', '10:45'], $this->formatSlots($slots));
    }

    public function test_customer_non_boundary_start_is_rejected(): void
    {
        $this->expectException(NonBoundaryStartException::class);

        $this->slotKey->occupiedSlots($this->time('10:07'), $this->time('11:00'));
    }

    public function test_admin_free_time_floors_start_and_ceils_end(): void
    {
        $slots = $this->slotKey->occupiedSlots($this->time('10:07'), $this->time('10:52'), true);

        $this->assertSame(['10:00', '10:15', '10:30', '10:45'], $this->formatSlots($slots));
    }

    public function test_admin_partial_slot_occupies_one_slot(): void
    {
        $slots = $this->slotKey->occupiedSlots($this->time('10:15'), $this->time('10:20'), true);

        $this->assertSame(['10:15'], $this->formatSlots($slots));
    }

    public function test_admin_boundary_end_does_not_occupy_next_slot(): void
    {
        $slots = $this->slotKey->occupiedSlots($this->time('10:15'), $this->time('10:30'), true);

        $this->assertSame(['10:15'], $this->formatSlots($slots));
    }

    public function test_adjacent_customer_reservations_do_not_share_slots(): void
    {
        $first = $this->formatSlots($this->slotKey->occupiedSlots($this->time('10:00'), $this->time('11:00')));
        $second = $this->formatSlots($this->slotKey->occupiedSlots($this->time('11:00'), $this->time('12:00')));

        $this->assertSame([], array_values(array_intersect($first, $second)));
    }

    public function test_overlapping_customer_reservations_share_expected_slots(): void
    {
        $first = $this->formatSlots($this->slotKey->occupiedSlots($this->time('10:00'), $this->time('11:00')));
        $second = $this->formatSlots($this->slotKey->occupiedSlots($this->time('10:30'), $this->time('11:30')));

        $this->assertSame(['10:30', '10:45'], array_values(array_intersect($first, $second)));
    }

    public function test_boundary_requires_aligned_minute_zero_seconds_and_zero_microseconds(): void
    {
        $this->assertTrue($this->slotKey->isBoundary($this->time('10:00')));
        $this->assertFalse($this->slotKey->isBoundary($this->time('10:07')));
        $this->assertFalse($this->slotKey->isBoundary($this->time('10:15:30')));
        $this->assertFalse($this->slotKey->isBoundary(
            $this->time('10:15')->setMicrosecond(1),
        ));
    }

    public function test_end_must_be_after_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->slotKey->occupiedSlots($this->time('10:00'), $this->time('10:00'));
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->slotKey->occupiedSlots($this->time('10:15'), $this->time('10:00'));
    }

    public function test_slot_minutes_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlotKey(0);
    }

    public function test_from_settings_reads_seeded_slot_minutes(): void
    {
        $this->seed(SettingsSeeder::class);

        // 予約枠は5分単位（§5分刻み）。
        $this->assertSame(5, SlotKey::fromSettings()->slotMinutes());
    }

    private function time(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-01 {$time}", 'Asia/Tokyo');
    }

    /**
     * @param  list<CarbonImmutable>  $slots
     * @return list<string>
     */
    private function formatSlots(array $slots): array
    {
        return array_map(
            static fn (CarbonImmutable $slot): string => $slot->format('H:i'),
            $slots,
        );
    }
}
