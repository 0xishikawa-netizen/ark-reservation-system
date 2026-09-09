<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domain\Integration\Provider\MockReservationStore;
use App\Domain\Integration\Service\InboundReservationSync;
use App\Domain\Integration\Service\ProviderReconciler;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationService;
use App\Models\Reservation;
use App\Models\ReservationProviderMapping;
use App\Models\ReservationProviderSyncState;
use App\Models\ReservationSyncConflict;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\IntegrationTestHelpers;
use Tests\TestCase;

/**
 * Phase 9 Reconciliation。大量上書きしない・safe self-heal と needs_attention を分離。
 */
final class ProviderReconcileTest extends TestCase
{
    use IntegrationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->useMockProvider();
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // 36. safe self-heal: 一致していれば last_seen 更新のみ、非 zero exit にしない
    public function test_in_sync_state_self_heals_without_conflict(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $data = $this->externalData('ext-1', $s, $c, $st);
        app(InboundReservationSync::class)->apply($data);

        // 現在の外部状態として同じものを積む
        $this->store()->setExternalState($data);

        $report = app(ProviderReconciler::class)->reconcile('mock');

        $this->assertSame(1, $report['in_sync']);
        $this->assertSame(0, $report['conflicts_opened']);
        $this->assertDatabaseHas('reservation_provider_sync_state', ['provider' => 'mock']);
        $this->assertNotNull(ReservationProviderSyncState::where('provider', 'mock')->value('last_reconcile_at'));
    }

    // 33/34. time / status mismatch on both sides → conflict、ARK は上書きされない
    public function test_both_sides_drifted_opens_conflict_without_overwrite(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $data = $this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00');
        $decision = app(InboundReservationSync::class)->apply($data);
        $reservation = Reservation::find($decision->reservationId);

        // ARK 側を変更
        app(ReservationService::class)->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id, staffId: $st->user_id, boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 15:00:00'), expectedVersion: 0,
            actorUserId: null, adminContext: true,
        ));

        // 外部側も別方向へ変更した現在状態
        $this->store()->setExternalState($this->externalData('ext-1', $s, $c, $st, '2026-10-01 20:00:00', '2026-10-01 21:00:00'));

        $report = app(ProviderReconciler::class)->reconcile('mock');

        $this->assertSame(1, $report['conflicts_opened']);
        $this->assertSame(1, ReservationSyncConflict::where('status', 'open')->count());
        $this->assertSame('2026-10-01 15:00:00', $reservation->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('conflict', ReservationProviderMapping::first()->sync_status->value);
    }

    // 32. mapped but missing on external side → stale（conflict にはしない）
    public function test_mapped_but_missing_marks_stale(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        app(InboundReservationSync::class)->apply($this->externalData('ext-1', $s, $c, $st));
        // 外部状態を空に（この予約は外部から消えた）
        // store は空のまま reconcile

        $report = app(ProviderReconciler::class)->reconcile('mock');

        $this->assertSame(1, $report['stale']);
        $this->assertSame('stale', ReservationProviderMapping::first()->sync_status->value);
        $this->assertSame(0, $report['conflicts_opened']);
    }

    // 31. ARK only（外部由来なのに mapping が無い）→ conflict
    public function test_ark_only_external_sourced_reservation_opens_conflict(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $this->arkReservation($c, $s, $st); // source=EXTERNAL, mapping なし

        $report = app(ProviderReconciler::class)->reconcile('mock');

        $this->assertSame(1, $report['ark_only']);
        $this->assertSame(1, ReservationSyncConflict::where('conflict_type', 'MISSING_MAPPING')->count());
    }

    // dry-run は書き込まない
    public function test_dry_run_does_not_write(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $this->arkReservation($c, $s, $st);

        app(ProviderReconciler::class)->reconcile('mock', dryRun: true);

        $this->assertSame(0, ReservationSyncConflict::count());
        $this->assertSame(0, ReservationProviderSyncState::count());
    }

    // command は差分ありで非 zero exit
    public function test_command_exits_non_zero_on_discrepancy(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $this->arkReservation($c, $s, $st);

        $this->artisan('reservations:reconcile-providers', ['--provider' => 'mock'])->assertFailed();
    }

    private function store(): MockReservationStore
    {
        return app(MockReservationStore::class);
    }
}
