<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneReservationSlotsTest extends TestCase
{
    use RefreshDatabase;

    private int $nextResourceId = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
        config()->set('retention.prune.reservation_resource_slots.past_days', 14);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_prunes_a_past_completed_reservation_slot(): void
    {
        $slot = $this->createSlot(ReservationStatus::Completed, now()->subDays(20)->toImmutable());

        $this->artisan('reservations:prune-slots')
            ->expectsOutput('削除したスロット件数: 1')
            ->assertSuccessful();

        $this->assertModelMissing($slot);
    }

    public function test_it_prunes_a_past_no_show_reservation_slot(): void
    {
        $slot = $this->createSlot(ReservationStatus::NoShow, now()->subDays(20)->toImmutable());

        $this->artisan('reservations:prune-slots')->assertSuccessful();

        $this->assertModelMissing($slot);
    }

    public function test_it_keeps_a_past_confirmed_reservation_slot(): void
    {
        $slot = $this->createSlot(ReservationStatus::Confirmed, now()->subDays(20)->toImmutable());

        $this->artisan('reservations:prune-slots')
            ->expectsOutput('削除したスロット件数: 0')
            ->assertSuccessful();

        $this->assertModelExists($slot);
    }

    public function test_it_keeps_a_recent_completed_reservation_slot(): void
    {
        $slot = $this->createSlot(ReservationStatus::Completed, now()->subDays(5)->toImmutable());

        $this->artisan('reservations:prune-slots')->assertSuccessful();

        $this->assertModelExists($slot);
    }

    public function test_it_keeps_a_future_completed_reservation_slot(): void
    {
        $slot = $this->createSlot(ReservationStatus::Completed, now()->addDay()->toImmutable());

        $this->artisan('reservations:prune-slots')->assertSuccessful();

        $this->assertModelExists($slot);
    }

    public function test_dry_run_only_reports_the_count_without_deleting(): void
    {
        $slot = $this->createSlot(ReservationStatus::Completed, now()->subDays(20)->toImmutable());

        $this->artisan('reservations:prune-slots', ['--dry-run' => true])
            ->expectsOutput('削除対象スロット件数: 1（dry-run: 削除なし）')
            ->assertSuccessful();

        $this->assertModelExists($slot);
    }

    public function test_it_is_idempotent(): void
    {
        $slot = $this->createSlot(ReservationStatus::Completed, now()->subDays(20)->toImmutable());

        $this->artisan('reservations:prune-slots')
            ->expectsOutput('削除したスロット件数: 1')
            ->assertSuccessful();
        $this->artisan('reservations:prune-slots')
            ->expectsOutput('削除したスロット件数: 0')
            ->assertSuccessful();

        $this->assertModelMissing($slot);
    }

    public function test_days_option_changes_the_cutoff(): void
    {
        $slot = $this->createSlot(ReservationStatus::Completed, now()->subDays(10)->toImmutable());

        $this->artisan('reservations:prune-slots')
            ->expectsOutput('削除したスロット件数: 0')
            ->assertSuccessful();
        $this->assertModelExists($slot);

        $this->artisan('reservations:prune-slots', ['--days' => 7])
            ->expectsOutput('削除したスロット件数: 1')
            ->assertSuccessful();

        $this->assertModelMissing($slot);
    }

    public function test_it_defensively_prunes_past_canceled_and_expired_reservation_slots(): void
    {
        $canceled = $this->createSlot(ReservationStatus::Canceled, now()->subDays(20)->toImmutable());
        $expired = $this->createSlot(ReservationStatus::Expired, now()->subDays(20)->toImmutable());

        $this->artisan('reservations:prune-slots')
            ->expectsOutput('削除したスロット件数: 2')
            ->assertSuccessful();

        $this->assertModelMissing($canceled);
        $this->assertModelMissing($expired);
    }

    private function createSlot(
        ReservationStatus $status,
        CarbonImmutable $slotStart,
    ): ReservationResourceSlot {
        $reservation = Reservation::factory()->create([
            'starts_at' => $slotStart,
            'ends_at' => $slotStart->addHour(),
            'status' => $status,
        ]);

        return $reservation->resourceSlots()->create([
            'resource_type' => ResourceType::Staff,
            'resource_id' => $this->nextResourceId++,
            'slot_start' => $slotStart,
        ]);
    }
}
