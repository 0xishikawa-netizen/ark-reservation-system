<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domain\Integration\Enum\OutboxStatus;
use App\Domain\Integration\Provider\MockReservationStore;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Models\Reservation;
use App\Models\ReservationProviderMapping;
use App\Models\ReservationSyncOutbox;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\IntegrationTestHelpers;
use Tests\TestCase;

/**
 * Phase 9 Outbound（ARK → External）Outbox / retry / idempotency。
 */
final class OutboundOutboxTest extends TestCase
{
    use IntegrationTestHelpers;
    use RefreshDatabase;

    private MockReservationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->store = $this->useMockProvider();
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeReservation(string $startsAt = '2026-10-01 10:00:00'): Reservation
    {
        [$c, $s, $st] = $this->reservationMasters();

        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $c->user_id,
            serviceId: (int) $s->id,
            staffId: (int) $st->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse($startsAt),
            source: ReservationSource::ArkWeb,
            actorUserId: null,
            notes: null,
            adminContext: true,
            paymentMethod: PaymentMethod::Onsite,
        ));
    }

    private function dispatch(): void
    {
        $this->artisan('reservations:dispatch-outbox', ['--sync' => true])->assertSuccessful();
    }

    // 15. ARK create → outbox → external create + mapping
    public function test_ark_create_enqueues_outbox_and_syncs_to_external(): void
    {
        $reservation = $this->makeReservation();

        $this->assertDatabaseHas('reservation_sync_outbox', [
            'reservation_id' => $reservation->id, 'operation' => 'create', 'status' => 'pending',
        ]);

        $this->dispatch();

        $this->assertSame(1, $this->store->appliedWriteCount('create'));
        $this->assertSame(OutboxStatus::Succeeded, ReservationSyncOutbox::first()->status);
        $this->assertSame(1, ReservationProviderMapping::where('reservation_id', $reservation->id)->count());
        $this->assertDatabaseHas('reservation_provider_sync_state', ['provider' => 'mock']);
    }

    // 16 & 17. ARK update / cancel も outbox → external
    public function test_ark_update_and_cancel_enqueue_and_sync(): void
    {
        $reservation = $this->makeReservation();
        $this->dispatch();
        $external = ReservationProviderMapping::where('reservation_id', $reservation->id)->value('external_reservation_id');

        app(ReservationService::class)->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: $reservation->staff_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 14:00:00'),
            expectedVersion: 0,
            actorUserId: null,
            adminContext: true,
        ));
        $this->dispatch();
        $this->assertSame(1, $this->store->appliedWriteCount('update'));

        app(ReservationService::class)->cancel($reservation->fresh(), '都合により', null);
        $this->dispatch();
        $this->assertSame(1, $this->store->appliedWriteCount('cancel'));
        $this->assertGreaterThanOrEqual(3, ReservationSyncOutbox::count());
        $this->assertSame(0, ReservationSyncOutbox::where('status', '!=', 'succeeded')->count());
        $this->assertNotSame('', (string) $external);
    }

    // 18 & 19. duplicate job / worker concurrent → 論理 external 操作は 1 回
    public function test_running_dispatcher_twice_does_not_double_send(): void
    {
        $this->makeReservation();
        $this->dispatch();
        $this->dispatch(); // 二重実行

        $this->assertSame(1, $this->store->appliedWriteCount('create'));
    }

    // 20 & 21. provider timeout / response loss（ambiguous）→ 確定失敗にしない・retry で収束
    public function test_ambiguous_failure_retries_and_converges_without_double_create(): void
    {
        $this->makeReservation();
        $this->store->ambiguousNextWrite(); // 1 回目は「外部反映済みだが応答喪失」

        $this->dispatch();

        $row = ReservationSyncOutbox::first();
        $this->assertSame(OutboxStatus::Pending, $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->available_at);

        // backoff を越えて再実行 → 同一 idempotency key で外部 create は 1 回のまま。
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-20 09:10:00'));
        $this->dispatch();

        $this->assertSame(OutboxStatus::Succeeded, ReservationSyncOutbox::first()->status);
        $this->assertSame(1, $this->store->appliedWriteCount('create'));
    }

    // 22 & 23. 429 / 5xx 相当（transient）→ retryable
    public function test_transient_failure_is_retried_with_backoff(): void
    {
        $this->makeReservation();
        $this->store->transientNextWrite();

        $this->dispatch();

        $row = ReservationSyncOutbox::first();
        $this->assertSame(OutboxStatus::Pending, $row->status);
        $this->assertTrue($row->available_at->greaterThan(now()));
        $this->assertSame('retryable', $row->last_error_category);
    }

    // 24. permanent failure → needs_attention（自動 retry しない）
    public function test_permanent_failure_becomes_needs_attention(): void
    {
        $this->makeReservation();
        $this->store->permanentNextWrite();

        $this->dispatch();

        $this->assertSame(OutboxStatus::NeedsAttention, ReservationSyncOutbox::first()->status);
        $this->assertSame('non_retryable', ReservationSyncOutbox::first()->last_error_category);
    }

    // 25. row.provider が現在の active provider と一致しない → 送信せず pending 保持（F-12）
    public function test_inactive_row_provider_parks_without_sending(): void
    {
        $this->makeReservation();
        // active provider を別の skeleton に切替（mock 宛の既存 row は送信対象外）。
        config()->set('reservation_integration.active_provider', 'peak_manager');

        $this->dispatch();

        // terminal 化せず pending に戻す（config 復帰で再開できる）。外部送信は行わない。
        $row = ReservationSyncOutbox::first();
        $this->assertSame(OutboxStatus::Pending, $row->status);
        $this->assertSame('config_error', $row->last_error_category);
        $this->assertSame(0, $this->store->appliedWriteCount('create'));
    }

    // F-04: worker crash で processing に固定された行を lease 期限後に再取得して完了できる
    public function test_stale_processing_row_is_reclaimed_after_lease_expires(): void
    {
        $reservation = $this->makeReservation();
        $row = ReservationSyncOutbox::first();

        // crash 相当: processing のまま 20 分放置。
        $row->forceFill([
            'status' => 'processing',
            'locked_at' => now()->subMinutes(20),
            'locked_by' => 'dead-worker',
            'attempts' => 1,
        ])->save();

        $this->dispatch();

        $row->refresh();
        $this->assertSame(OutboxStatus::Succeeded, $row->status);
        $this->assertSame(1, $this->store->appliedWriteCount('create'));
    }

    // インバウンド起点の予約は折り返し送信しない
    public function test_external_sourced_reservation_does_not_enqueue_outbound(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $c->user_id, serviceId: (int) $s->id, staffId: (int) $st->user_id,
            boothId: null, startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::External, actorUserId: null, notes: null, adminContext: true,
            paymentMethod: PaymentMethod::Onsite,
        ));

        $this->assertSame(0, ReservationSyncOutbox::count());
    }
}
