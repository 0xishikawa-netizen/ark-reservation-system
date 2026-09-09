<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Dto\InboundDecision;
use App\Domain\Integration\Service\InboundReservationSync;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationService;
use App\Models\Reservation;
use App\Models\ReservationProviderMapping;
use App\Models\ReservationSyncConflict;
use App\Models\ReservationSyncEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\IntegrationTestHelpers;
use Tests\TestCase;

/**
 * Phase 9 Inbound（External → ARK）Failure Matrix。
 */
final class InboundReservationSyncTest extends TestCase
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

    private function sync(): InboundReservationSync
    {
        return app(InboundReservationSync::class);
    }

    // 1. 0 件
    public function test_zero_items_is_a_noop(): void
    {
        $this->assertSame(0, ReservationSyncEvent::count());
        $this->assertSame(0, Reservation::count());
    }

    // 2 & 3. 新規（1件 / 複数件）
    public function test_creates_ark_reservations_for_new_external_items(): void
    {
        [$c, $s, $st] = $this->reservationMasters();

        $d1 = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00'));
        $d2 = $this->sync()->apply($this->externalData('ext-2', $s, $c, $st, '2026-10-01 12:00:00', '2026-10-01 13:00:00'));

        $this->assertSame(InboundDecision::CREATE, $d1->action);
        $this->assertSame(InboundDecision::CREATE, $d2->action);
        $this->assertSame(2, Reservation::where('source', 'EXTERNAL')->count());
        $this->assertSame(2, ReservationProviderMapping::count());
        $this->assertDatabaseHas('reservation_provider_mappings', ['provider' => 'mock', 'external_reservation_id' => 'ext-1']);
    }

    // 4 & 6. 同じデータ再取得 / existing mapping → NO-OP
    public function test_reapplying_same_data_is_a_noop_and_does_not_duplicate(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $data = $this->externalData('ext-1', $s, $c, $st);

        $this->sync()->apply($data);
        $d = $this->sync()->apply($data);

        $this->assertSame(InboundDecision::NO_OP, $d->action);
        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, ReservationProviderMapping::count());
    }

    // 5. duplicate external id が同時に来ても mapping 1 / reservation 1
    public function test_duplicate_external_id_converges_to_one(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $data = $this->externalData('dup-1', $s, $c, $st);

        $a = $this->sync()->apply($data);
        $b = $this->sync()->apply($data);

        $this->assertSame(InboundDecision::CREATE, $a->action);
        $this->assertSame(InboundDecision::NO_OP, $b->action);
        $this->assertSame(1, ReservationProviderMapping::where('external_reservation_id', 'dup-1')->count());
    }

    // 7. external update → reschedule
    public function test_external_time_change_reschedules_ark(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $created = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00'));

        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 14:00:00', '2026-10-01 15:00:00', updatedAt: '2026-09-20 10:00:00'));

        $this->assertSame(InboundDecision::UPDATE, $d->action);
        $this->assertSame(
            '2026-10-01 14:00:00',
            Reservation::find($created->reservationId)->starts_at->format('Y-m-d H:i:s'),
        );
    }

    // 8. external cancel → cancel
    public function test_external_cancel_cancels_ark(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $created = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st));

        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, status: 'canceled', canceled: true, updatedAt: '2026-09-20 10:00:00'));

        $this->assertSame(InboundDecision::CANCEL, $d->action);
        $this->assertSame('canceled', Reservation::find($created->reservationId)->status->value);
    }

    // 9. stale response → 巻き戻さない
    public function test_stale_response_is_skipped(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $created = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00', updatedAt: '2026-09-20 12:00:00'));
        // 新しい更新を反映
        $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 15:00:00', '2026-10-01 16:00:00', updatedAt: '2026-09-20 13:00:00'));

        // 遅れて古いバージョンが来る
        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00', updatedAt: '2026-09-20 12:00:00'));

        $this->assertSame(InboundDecision::SKIPPED_STALE, $d->action);
        $this->assertSame('2026-10-01 15:00:00', Reservation::find($created->reservationId)->starts_at->format('Y-m-d H:i:s'));
    }

    // 10. malformed（時刻不正）→ skipped
    public function test_malformed_time_is_skipped(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $d = $this->sync()->apply($this->externalData('bad-1', $s, $c, $st, '2026-10-01 11:00:00', '2026-10-01 10:00:00'));

        $this->assertSame(InboundDecision::SKIPPED_UNSUPPORTED, $d->action);
        $this->assertSame(0, Reservation::count());
    }

    // 未知 status → CONFLICT UNSUPPORTED_STATE（正常扱いしない）
    public function test_unknown_status_becomes_conflict(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, status: 'weird_state'));

        $this->assertSame(InboundDecision::CONFLICT, $d->action);
        $this->assertSame('UNSUPPORTED_STATE', $d->conflictType);
        $this->assertSame(1, ReservationSyncConflict::where('conflict_type', 'UNSUPPORTED_STATE')->count());
        $this->assertSame(0, Reservation::count());
    }

    // 13 相当（順序内での ARK 変更 + 外部変更）→ CONFLICT（silent overwrite しない）
    public function test_both_sides_changed_is_a_conflict_not_an_overwrite(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $created = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00'));
        $reservation = Reservation::find($created->reservationId);

        // ARK 側が独自に変更（reschedule 済みで fingerprint がずれる）
        app(ReservationService::class)->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: $st->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 16:00:00'),
            expectedVersion: 0,
            actorUserId: null,
            adminContext: true,
        ));

        // 外部側も別の時刻へ変更
        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 18:00:00', '2026-10-01 19:00:00', updatedAt: '2026-09-20 11:00:00'));

        $this->assertSame(InboundDecision::CONFLICT, $d->action);
        $this->assertSame('TIME_CHANGED_BOTH', $d->conflictType);
        // ARK は外部で上書きされていない。
        $this->assertSame('2026-10-01 16:00:00', $reservation->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('conflict', ReservationProviderMapping::where('external_reservation_id', 'ext-1')->first()->sync_status->value);
    }

    // F-02: 順序比較材料が無い Provider は既存予約の異なる snapshot を自動反映せず CONFLICT
    public function test_existing_reservation_without_ordering_signal_is_a_conflict_not_an_update(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $created = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00'));

        // 2 回目は別時刻だが updatedAt / version が無い → 順序が判定できない。
        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 14:00:00', '2026-10-01 15:00:00'));

        $this->assertSame(InboundDecision::CONFLICT, $d->action);
        $this->assertSame('STATUS_CHANGED_BOTH', $d->conflictType);
        // ARK は上書きされない。
        $this->assertSame('2026-10-01 10:00:00', Reservation::find($created->reservationId)->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('conflict', ReservationProviderMapping::where('external_reservation_id', 'ext-1')->first()->sync_status->value);
    }

    // F-07: confirmed 予約が外部で completed / no_show へ遷移しても自動反映しない（entitlement 副作用防止）
    public function test_non_confirmed_external_transition_is_a_conflict(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $created = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00'));

        $d = $this->sync()->apply($this->externalData('ext-1', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00', status: 'completed', updatedAt: '2026-09-20 10:00:00'));

        $this->assertSame(InboundDecision::CONFLICT, $d->action);
        $this->assertSame('STATUS_CHANGED_BOTH', $d->conflictType);
        $this->assertSame('confirmed', Reservation::find($created->reservationId)->status->value);
    }

    // MISSING_MAPPING: 解決できない customer/service で create しない
    public function test_unresolvable_refs_do_not_create_garbage(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $data = new ExternalReservationData(
            provider: 'mock', externalReservationId: 'ext-x',
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            endsAt: CarbonImmutable::parse('2026-10-01 11:00:00'),
            externalStatus: 'confirmed', isCanceled: false,
            externalCustomerId: '999999', serviceRef: '999999',
        );

        $d = $this->sync()->apply($data);

        $this->assertSame(InboundDecision::CONFLICT, $d->action);
        $this->assertSame('MISSING_MAPPING', $d->conflictType);
        $this->assertSame(0, Reservation::count());
    }

    // at-least-once: 予約作成後に mapping 作成前に停止した孤児を、次 poll で二重 create しない
    public function test_orphan_external_reservation_is_adopted_not_duplicated(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        // 「予約はできたが mapping が無い」状態を再現
        $orphan = Reservation::factory()->create([
            'customer_id' => $c->user_id, 'service_id' => $s->id, 'staff_id' => $st->user_id,
            'starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-10-01 11:00:00',
            'status' => 'confirmed', 'source' => 'EXTERNAL', 'version' => 0,
        ]);

        $d = $this->sync()->apply($this->externalData('ext-orphan', $s, $c, $st, '2026-10-01 10:00:00', '2026-10-01 11:00:00'));

        $this->assertSame(InboundDecision::NO_OP, $d->action);
        $this->assertSame($orphan->id, $d->reservationId);
        $this->assertSame(1, Reservation::where('source', 'EXTERNAL')->count());
        $this->assertSame(1, ReservationProviderMapping::where('reservation_id', $orphan->id)->count());
    }

    // sync event に PII / raw payload が残らない
    public function test_sync_events_contain_no_pii_or_raw_payload(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $this->sync()->apply($this->externalData('ext-secret-9999', $s, $c, $st));

        $event = ReservationSyncEvent::firstOrFail();
        $this->assertNotNull($event->external_reservation_id_masked);
        $this->assertStringNotContainsString('ext-secret-9999', $event->external_reservation_id_masked);
        $this->assertStringStartsWith('****', $event->external_reservation_id_masked);
        foreach ($event->getAttributes() as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('テスト 太郎', $value);
                $this->assertStringNotContainsString('****1234', $value);
            }
        }
    }
}
