<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipReservationService;
use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Reservation;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class MembershipReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private MembershipReservationService $service;

    private MembershipLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        $this->service = app(MembershipReservationService::class);
        $this->ledger = app(MembershipLedgerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Customer, Membership} */
    private function activeMembership(int $grant = 4, string $period = '2026-09-01'): array
    {
        $customer = Customer::factory()->create();
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'current_period_start' => $period,
            'current_period_end' => Carbon::parse($period)->addMonth()->toDateString(),
            'period_available' => 0,
        ]);
        if ($grant > 0) {
            $this->ledger->grant($membership, $period, $grant);
        }

        return [$customer, $membership];
    }

    private function reservationFor(Customer $customer): Reservation
    {
        return Reservation::factory()->create(['customer_id' => $customer->user_id]);
    }

    public function test_reserve_consumes_one_and_snapshots_period_and_policy(): void
    {
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);

        $usage = $this->service->reserve($reservation);

        $this->assertSame(MembershipReservationUsageStatus::Reserved, $usage->status);
        $this->assertSame('2026-09-01', $usage->period_start->toDateString());
        $this->assertSame(MembershipNoShowPolicy::Consume, $usage->no_show_policy);
        $this->assertSame(3, $this->ledger->available($membership));
        $this->assertSame(1, $this->ledger->held($membership));
        $this->assertSame(3, $membership->fresh()->period_available);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.reserved']);
    }

    public function test_reserve_is_idempotent(): void
    {
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);

        $a = $this->service->reserve($reservation);
        $b = $this->service->reserve($reservation);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, MembershipReservationUsage::query()->count());
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', MembershipUsageType::Reserve->value)->count());
        $this->assertSame(3, $this->ledger->available($membership));
    }

    public function test_reserve_without_bookable_membership_throws(): void
    {
        $customer = Customer::factory()->create();
        Membership::factory()->paused()->create(['customer_id' => $customer->user_id]);
        $reservation = $this->reservationFor($customer);

        $this->expectException(InsufficientMembershipBalanceException::class);
        $this->service->reserve($reservation);
    }

    public function test_reserve_with_zero_available_throws(): void
    {
        [$customer] = $this->activeMembership(grant: 0);
        $reservation = $this->reservationFor($customer);

        $this->expectException(InsufficientMembershipBalanceException::class);
        $this->service->reserve($reservation);
    }

    public function test_reserve_snapshots_current_setting(): void
    {
        app(Settings::class)->set('membership.no_show_policy', 'restore');
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);

        $usage = $this->service->reserve($reservation);

        $this->assertSame(MembershipNoShowPolicy::Restore, $usage->no_show_policy);
    }

    public function test_release_restores_available_and_is_idempotent(): void
    {
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);
        $this->service->reserve($reservation);

        $this->service->release($reservation);
        $this->service->release($reservation);

        $this->assertSame(4, $this->ledger->available($membership));
        $this->assertSame(0, $this->ledger->held($membership));
        $this->assertSame(MembershipReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', MembershipUsageType::Release->value)->count());
    }

    public function test_consume_nets_zero_change_and_marks_one_used(): void
    {
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);
        $this->service->reserve($reservation);

        $this->service->consume($reservation);
        $this->service->consume($reservation);

        $this->assertSame(3, $this->ledger->available($membership));
        $this->assertSame(0, $this->ledger->held($membership));
        $this->assertSame(MembershipReservationUsageStatus::Consumed, $this->usage($reservation)->status);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', MembershipUsageType::Release->value)->count());
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', MembershipUsageType::Consume->value)->count());
    }

    public function test_handle_no_show_uses_snapshot_not_current_setting(): void
    {
        app(Settings::class)->set('membership.no_show_policy', 'consume');
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);
        $this->service->reserve($reservation);

        // 後から restore に変えても、既存予約は snapshot（consume）に従う。
        app(Settings::class)->set('membership.no_show_policy', 'restore');
        $this->service->handleNoShow($reservation);

        $this->assertSame(3, $this->ledger->available($membership), 'consume 相当で 1 消化');
        $this->assertSame(MembershipReservationUsageStatus::Consumed, $this->usage($reservation)->status);
    }

    public function test_handle_no_show_restore_snapshot_releases(): void
    {
        app(Settings::class)->set('membership.no_show_policy', 'restore');
        [$customer, $membership] = $this->activeMembership();
        $reservation = $this->reservationFor($customer);
        $this->service->reserve($reservation);

        app(Settings::class)->set('membership.no_show_policy', 'consume');
        $this->service->handleNoShow($reservation);

        $this->assertSame(4, $this->ledger->available($membership), 'restore 相当で回数を返す');
        $this->assertSame(MembershipReservationUsageStatus::Released, $this->usage($reservation)->status);
    }

    public function test_cross_period_cancel_does_not_revive_expired_entitlement(): void
    {
        [$customer, $membership] = $this->activeMembership(grant: 4, period: '2026-09-01');
        $reservation = $this->reservationFor($customer);
        $this->service->reserve($reservation); // 9月期: available 3 / held 1

        // 期がロールオーバーして 10 月期になった（新 GRANT）。
        $membership->forceFill([
            'current_period_start' => '2026-10-01',
            'current_period_end' => '2026-11-01',
        ])->save();
        $this->ledger->grant($membership, '2026-10-01', 4); // 10月期: available 4

        $this->service->release($reservation); // 予約は 9 月期のものだった

        // 10 月期（当期）の残数は増えない。
        $this->assertSame(4, $this->ledger->available($membership), '当期は不変（期またぎ復活なし）');
        $this->assertSame(4, $membership->fresh()->period_available);
        // 9 月期は RELEASE +1 と ADJUST -1 で相殺され、正味は消化前と同じ。
        $this->assertSame(3, $this->ledger->available($membership, '2026-09-01'));
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('type', MembershipUsageType::Adjust->value)
            ->where('dedupe_key', "mbr-expire:{$reservation->id}")
            ->count());
        $this->assertSame(MembershipReservationUsageStatus::Released, $this->usage($reservation)->status);
    }

    private function usage(Reservation $reservation): MembershipReservationUsage
    {
        return MembershipReservationUsage::query()->where('reservation_id', $reservation->id)->firstOrFail();
    }
}
