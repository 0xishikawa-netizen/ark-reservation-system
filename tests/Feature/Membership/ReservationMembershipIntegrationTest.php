<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Reservation\RescheduleInput;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ReservationMembershipIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Customer, Service, Staff} */
    private function masters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
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

    private function grantMembership(Customer $customer, int $grant = 4): Membership
    {
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-09-01', $grant);

        return $membership;
    }

    private function input(Customer $c, Service $s, Staff $st, PaymentMethod $pm, ?CarbonImmutable $at = null): ReservationInput
    {
        return new ReservationInput(
            customerId: (int) $c->user_id,
            serviceId: (int) $s->id,
            staffId: (int) $st->user_id,
            boothId: null,
            startsAt: $at ?? CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $c->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: $pm,
        );
    }

    private function svc(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function usage(Reservation $r): ?MembershipReservationUsage
    {
        return MembershipReservationUsage::query()->where('reservation_id', $r->id)->first();
    }

    public function test_membership_create_reserves_one_within_the_reservation_flow(): void
    {
        [$c, $s, $st] = $this->masters();
        $membership = $this->grantMembership($c, 4);

        $r = $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership));

        $this->assertSame(ReservationStatus::Confirmed, $r->status);
        $this->assertSame(PaymentMethod::Membership, $r->payment_method);
        $usage = $this->usage($r);
        $this->assertNotNull($usage);
        $this->assertSame(MembershipReservationUsageStatus::Reserved, $usage->status);
        $this->assertSame(3, app(MembershipLedgerService::class)->available($membership));
        $this->assertSame(1, app(MembershipLedgerService::class)->held($membership));
        $this->assertSame(4, $r->resourceSlots()->count());
        $this->assertSame(0, \App\Models\TicketTransaction::query()->count(), 'membership 予約は ticket 台帳に触れない');
    }

    public function test_membership_create_without_entitlement_rolls_back_everything(): void
    {
        [$c, $s, $st] = $this->masters();
        // membership なし。

        try {
            $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership));
            $this->fail('残不足で例外にならなかった');
        } catch (InsufficientMembershipBalanceException) {
            // ok
        }

        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_resource_slots', 0);
        $this->assertDatabaseCount('membership_reservation_usages', 0);
        $this->assertDatabaseCount('membership_usage_transactions', 0);
    }

    public function test_membership_create_slot_conflict_rolls_back_reserve(): void
    {
        [$c, $s, $st] = $this->masters();
        $membership = $this->grantMembership($c, 4);
        $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Onsite));

        try {
            $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership));
            $this->fail('slot 競合で例外にならなかった');
        } catch (SlotUnavailableException) {
            // ok
        }

        $this->assertDatabaseCount('membership_reservation_usages', 0);
        $this->assertSame(4, app(MembershipLedgerService::class)->available($membership));
    }

    public function test_cancel_releases_and_complete_consumes(): void
    {
        [$c, $s, $st] = $this->masters();
        $membership = $this->grantMembership($c, 4);
        $ledger = app(MembershipLedgerService::class);

        $r1 = $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership, CarbonImmutable::parse('2026-10-01 10:00:00')));
        $this->svc()->cancel($r1, 'テスト', null);
        $this->assertSame(4, $ledger->available($membership));
        $this->assertSame(MembershipReservationUsageStatus::Released, $this->usage($r1)->status);

        $r2 = $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership, CarbonImmutable::parse('2026-10-01 12:00:00')));
        $this->svc()->markCompleted($r2, null);
        $this->assertSame(3, $ledger->available($membership));
        $this->assertSame(MembershipReservationUsageStatus::Consumed, $this->usage($r2)->status);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', 'CONSUME')->count());
    }

    public function test_no_show_follows_snapshot_policy(): void
    {
        app(\App\Support\Settings\Settings::class)->set('membership.no_show_policy', 'consume');
        [$c, $s, $st] = $this->masters();
        $membership = $this->grantMembership($c, 4);

        $r = $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership));
        // 予約後にポリシー変更しても遡及しない。
        app(\App\Support\Settings\Settings::class)->set('membership.no_show_policy', 'restore');
        $this->svc()->markNoShow($r, null);

        $this->assertSame(3, app(MembershipLedgerService::class)->available($membership));
        $this->assertSame(MembershipReservationUsageStatus::Consumed, $this->usage($r)->status);
    }

    public function test_ticket_and_card_bookings_do_not_touch_membership_ledger(): void
    {
        [$c, $s, $st] = $this->masters();
        $this->grantMembership($c, 4);

        // onsite 予約 → membership も ticket も触らない。
        $onsite = $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Onsite));
        $this->assertNull($this->usage($onsite));
        $this->assertSame(0, MembershipUsageTransaction::query()->where('type', 'RESERVE')->count());

        $this->svc()->cancel($onsite, 'x', null);
        $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Onsite));
        $this->assertSame(0, MembershipUsageTransaction::query()->where('type', 'RESERVE')->count());
    }

    public function test_reschedule_membership_reservation_keeps_usage(): void
    {
        [$c, $s, $st] = $this->masters();
        $membership = $this->grantMembership($c, 4);
        $r = $this->svc()->create($this->input($c, $s, $st, PaymentMethod::Membership, CarbonImmutable::parse('2026-10-01 10:00:00')));

        $this->svc()->reschedule(new RescheduleInput(
            reservationId: (int) $r->id,
            staffId: (int) $st->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 14:00:00'),
            expectedVersion: 0,
            actorUserId: (int) $c->user_id,
            adminContext: false,
        ));

        $usage = $this->usage($r->fresh());
        $this->assertSame(MembershipReservationUsageStatus::Reserved, $usage->status);
        $this->assertSame(3, app(MembershipLedgerService::class)->available($membership), '残数は変わらない');
    }
}
