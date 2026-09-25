<?php

declare(strict_types=1);

namespace Tests\Feature\Visit;

use App\Domain\Visit\VisitCompletionService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Enums\Reservation\PaymentMethod as ReservationPaymentMethod;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Visit\VisitStatus;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VisitCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_normal_completion_atomically_creates_visit_treatment_staff_snapshot_and_status(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 15:30:00', 'UTC'));
        $service = Service::factory()->create(['name' => '整体60', 'duration_min' => 60]);
        $staff = Staff::factory()->create(['display_name' => '主担当']);
        $reservation = $this->reservation(service: $service, staff: $staff);
        $service->update(['duration_min' => 90]);

        $result = $this->completion()->completeReservation($reservation);

        $this->assertSame(ReservationStatus::Completed, $result->reservation->status);
        $this->assertSame(VisitStatus::Completed, $result->visit->status);
        $this->assertSame('2026-09-25', $result->visit->business_date->toDateString());
        $this->assertNull($result->visit->started_at);
        $this->assertSame($staff->user_id, $result->visit->primary_staff_id);
        $this->assertSame('主担当', $result->visit->primary_staff_name_snapshot);
        $this->assertSame(1, $result->visit->visit_sequence);
        $this->assertFalse($result->visit->future_reservation_exists_at_checkout);
        $this->assertTrue($result->accountingPending);

        $treatment = $result->visit->treatments()->sole();
        $this->assertSame('整体60', $treatment->service_name_snapshot);
        $this->assertSame(60, $treatment->actual_minutes);
        $this->assertSame($staff->user_id, $treatment->staffAssignments()->sole()->staff_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.completed',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_explicit_staff_request_is_snapshotted_without_assuming_assigned_staff_means_nomination(): void
    {
        $assignedOnly = $this->reservation();
        $assignedVisit = $this->completion()->completeReservation($assignedOnly)->visit;
        $this->assertFalse($assignedVisit->staff_requested_at_checkout);
        $this->assertNull($assignedVisit->requested_staff_id_at_checkout);

        $nominated = $this->reservation();
        $nominated->forceFill(['is_staff_requested' => true])->save();
        $nominatedVisit = $this->completion()->completeReservation($nominated)->visit;
        $this->assertTrue($nominatedVisit->staff_requested_at_checkout);
        $this->assertSame($nominated->staff_id, $nominatedVisit->requested_staff_id_at_checkout);
    }

    public function test_first_visit_snapshots_gender_and_age_at_business_date_not_current_profile_age(): void
    {
        $customer = Customer::factory()->create(['gender' => 'male', 'birthday' => '1996-09-25']);
        $reservation = $this->reservation($customer);
        $visit = $this->completion()->completeReservation($reservation)->visit;

        $this->assertSame(1, $visit->visit_sequence);
        $this->assertSame('male', $visit->first_visit_gender_snapshot);
        $this->assertSame(29, $visit->first_visit_age_years_snapshot);

        $customer->update(['gender' => 'female', 'birthday' => '1986-09-25']);
        $this->assertSame('male', $visit->fresh()->first_visit_gender_snapshot);
        $this->assertSame(29, $visit->fresh()->first_visit_age_years_snapshot);
    }

    public function test_first_visit_age_uses_tokyo_business_date_across_utc_midnight(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 15:30:00', 'UTC'));
        $customer = Customer::factory()->create(['birthday' => '1996-09-25']);
        $visit = $this->completion()->completeReservation($this->reservation($customer))->visit;

        $this->assertSame('2026-09-25', $visit->business_date->toDateString());
        $this->assertSame(30, $visit->first_visit_age_years_snapshot);
    }

    public function test_first_visit_age_uses_existing_visit_business_date_when_draft_is_completed_later(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 15:30:00', 'UTC'));
        $customer = Customer::factory()->create(['birthday' => '1996-09-25']);
        $reservation = $this->reservation($customer);
        Visit::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'business_date' => '2026-09-24',
            'status' => VisitStatus::Draft,
        ]);

        $visit = $this->completion()->completeReservation($reservation)->visit;
        $this->assertSame('2026-09-24', $visit->business_date->toDateString());
        $this->assertSame(29, $visit->first_visit_age_years_snapshot);
    }

    public function test_retry_with_same_or_new_request_returns_the_single_completed_result(): void
    {
        $reservation = $this->reservation();
        $operationId = 'a56a2d8a-9f91-5bc0-b0c7-a1e95d5f3301';

        $first = $this->completion()->completeReservation($reservation, operationId: $operationId);
        $sameKey = $this->completion()->completeReservation($reservation, operationId: $operationId);
        $buttonRetry = $this->completion()->completeReservation($reservation);

        $this->assertSame($first->visit->id, $sameKey->visit->id);
        $this->assertSame($first->visit->id, $buttonRetry->visit->id);
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('visit_treatments', 1);
        $this->assertDatabaseCount('visit_treatment_staff', 1);
        $this->assertSame(1, $this->auditCount('reservation.completed', (string) $reservation->id));
    }

    public function test_future_reservation_snapshot_uses_exists_active_status_and_completion_instant(): void
    {
        $service = Service::factory()->create(['duration_min' => 60]);
        $staff = Staff::factory()->create();

        $none = $this->reservation(service: $service, staff: $staff);
        $noneVisit = $this->completion()->completeReservation($none)->visit;
        $this->assertFalse($noneVisit->future_reservation_exists_at_checkout);

        $oneCustomer = Customer::factory()->create();
        $one = $this->reservation($oneCustomer, $service, $staff);
        $this->reservation($oneCustomer, $service, $staff, '2026-10-01 10:00:00');
        $this->assertTrue($this->completion()->completeReservation($one)->visit->future_reservation_exists_at_checkout);

        $twoCustomer = Customer::factory()->create();
        $two = $this->reservation($twoCustomer, $service, $staff);
        $this->reservation($twoCustomer, $service, $staff, '2026-10-01 10:00:00');
        $this->reservation($twoCustomer, $service, $staff, '2026-10-02 10:00:00');
        $this->assertTrue($this->completion()->completeReservation($two)->visit->future_reservation_exists_at_checkout);

        $sameDayCustomer = Customer::factory()->create();
        $sameDay = $this->reservation($sameDayCustomer, $service, $staff);
        $this->reservation($sameDayCustomer, $service, $staff, '2026-09-24 09:00:00');
        $this->assertTrue($this->completion()->completeReservation($sameDay)->visit->future_reservation_exists_at_checkout);

        $canceledCustomer = Customer::factory()->create();
        $canceledTarget = $this->reservation($canceledCustomer, $service, $staff);
        $this->reservation($canceledCustomer, $service, $staff, '2026-10-01 10:00:00', ReservationStatus::Canceled);
        $this->assertFalse($this->completion()->completeReservation($canceledTarget)->visit->future_reservation_exists_at_checkout);
    }

    public function test_snapshot_never_changes_after_future_booking_creation_or_cancellation(): void
    {
        $service = Service::factory()->create();
        $staff = Staff::factory()->create();
        $falseCustomer = Customer::factory()->create();
        $falseTarget = $this->reservation($falseCustomer, $service, $staff);
        $falseVisit = $this->completion()->completeReservation($falseTarget)->visit;
        $this->reservation($falseCustomer, $service, $staff, '2026-10-01 10:00:00');
        $this->assertFalse($falseVisit->fresh()->future_reservation_exists_at_checkout);

        $trueCustomer = Customer::factory()->create();
        $trueTarget = $this->reservation($trueCustomer, $service, $staff);
        $future = $this->reservation($trueCustomer, $service, $staff, '2026-10-01 10:00:00');
        $trueVisit = $this->completion()->completeReservation($trueTarget)->visit;
        $future->forceFill(['status' => ReservationStatus::Canceled])->save();
        $this->assertTrue($trueVisit->fresh()->future_reservation_exists_at_checkout);
    }

    public function test_existing_valid_draft_checkout_is_finalized_but_missing_checkout_is_not_invented(): void
    {
        $reservation = $this->reservation();
        $visit = Visit::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'business_date' => '2026-09-25',
        ]);
        $checkout = Checkout::factory()->create(['visit_id' => $visit->id]);
        CheckoutLine::factory()->create(['checkout_id' => $checkout->id, 'is_staff_allocatable' => false]);
        CheckoutTender::factory()->create([
            'checkout_id' => $checkout->id,
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => 11000,
        ]);

        $result = $this->completion()->completeReservation($reservation);
        $retry = $this->completion()->completeReservation($reservation);

        $this->assertFalse($result->accountingPending);
        $this->assertSame($result->visit->id, $retry->visit->id);
        $this->assertSame(CheckoutStatus::Finalized, $checkout->fresh()->status);
        $this->assertDatabaseCount('checkouts', 1);
        $this->assertSame(1, $this->auditCount('checkout.finalized', (string) $checkout->id));
    }

    public function test_checkout_failure_rolls_back_new_facts_status_and_ticket_consumption(): void
    {
        $reservation = $this->reservation(paymentMethod: ReservationPaymentMethod::Ticket);
        [$wallet, $usage] = $this->heldTicket($reservation);
        $visit = Visit::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
        ]);
        Checkout::factory()->create(['visit_id' => $visit->id]); // 明細なしのためfinalizeで失敗する。

        try {
            $this->completion()->completeReservation($reservation);
            $this->fail('不完全な会計で完了できました。');
        } catch (ValidationException) {
            $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
            $this->assertSame(VisitStatus::Draft, $visit->fresh()->status);
            $this->assertNull($visit->fresh()->completion_operation_id);
            $this->assertDatabaseCount('visit_treatments', 0);
            $this->assertSame(TicketReservationUsageStatus::Held, $usage->fresh()->status);
            $this->assertSame(0, TicketTransaction::query()->where('ticket_wallet_id', $wallet->id)->where('type', TicketTransactionType::Consume)->count());
        }
    }

    public function test_ticket_and_membership_consumption_remain_idempotent_and_traceable_to_visit(): void
    {
        $ticketReservation = $this->reservation(paymentMethod: ReservationPaymentMethod::Ticket);
        [$wallet, $ticketUsage] = $this->heldTicket($ticketReservation);
        $ticketVisit = $this->completion()->completeReservation($ticketReservation)->visit;
        $this->completion()->completeReservation($ticketReservation);

        $this->assertSame(TicketReservationUsageStatus::Consumed, $ticketUsage->fresh()->status);
        $this->assertSame($ticketVisit->id, $ticketUsage->reservation->visit?->id);
        $this->assertSame(1, TicketTransaction::query()->where('ticket_wallet_id', $wallet->id)->where('type', TicketTransactionType::Consume)->count());

        $membershipReservation = $this->reservation(paymentMethod: ReservationPaymentMethod::Membership);
        [$membership, $membershipUsage] = $this->reservedMembership($membershipReservation);
        $membershipVisit = $this->completion()->completeReservation($membershipReservation)->visit;
        $this->completion()->completeReservation($membershipReservation);

        $this->assertSame(MembershipReservationUsageStatus::Consumed, $membershipUsage->fresh()->status);
        $this->assertSame($membershipVisit->id, $membershipUsage->reservation->visit?->id);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('membership_id', $membership->id)->where('type', MembershipUsageType::Consume)->count());
    }

    public function test_membership_is_not_consumed_when_checkout_finalization_rolls_back(): void
    {
        $reservation = $this->reservation(paymentMethod: ReservationPaymentMethod::Membership);
        [$membership, $usage] = $this->reservedMembership($reservation);
        $visit = Visit::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
        ]);
        Checkout::factory()->create(['visit_id' => $visit->id]);

        try {
            $this->completion()->completeReservation($reservation);
            $this->fail('不完全な会計で完了できました。');
        } catch (ValidationException) {
            $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
            $this->assertSame(MembershipReservationUsageStatus::Reserved, $usage->fresh()->status);
            $this->assertSame(0, MembershipUsageTransaction::query()->where('membership_id', $membership->id)->where('type', MembershipUsageType::Consume)->count());
        }
    }

    public function test_preentered_multiple_treatments_preserve_long_boundaries(): void
    {
        foreach ([[60], [61], [30, 30], [30, 45]] as $index => $minutes) {
            $reservation = $this->reservation();
            $visit = Visit::factory()->create([
                'customer_id' => $reservation->customer_id,
                'reservation_id' => $reservation->id,
            ]);
            foreach ($minutes as $order => $actualMinutes) {
                $treatment = VisitTreatment::factory()->create([
                    'visit_id' => $visit->id,
                    'service_id' => $reservation->service_id,
                    'actual_minutes' => $actualMinutes,
                    'sort_order' => $order,
                ]);
                VisitTreatmentStaff::factory()->create([
                    'visit_treatment_id' => $treatment->id,
                    'staff_id' => $reservation->staff_id,
                    'actual_minutes' => $actualMinutes,
                ]);
            }

            $completed = $this->completion()->completeReservation($reservation)->visit;
            $isLong = (int) $completed->treatments()->sum('actual_minutes') > 60;
            $this->assertSame(in_array($index, [1, 3], true), $isLong);
        }
    }

    public function test_visit_sequence_reaches_first_second_sixth_and_tenth_without_duplicates(): void
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();
        $staff = Staff::factory()->create();
        $sequences = [];
        for ($number = 1; $number <= 10; $number++) {
            $reservation = $this->reservation($customer, $service, $staff, "2026-10-{$number} 10:00:00");
            $sequences[] = $this->completion()->completeReservation($reservation)->visit->visit_sequence;
        }

        $this->assertSame(range(1, 10), $sequences);
        $this->assertSame([1, 2, 6, 10], array_values(array_intersect($sequences, [1, 2, 6, 10])));
        $this->assertSame(10, Visit::query()->where('customer_id', $customer->user_id)->distinct()->count('visit_sequence'));
    }

    public function test_cancelled_no_show_and_legacy_completed_reservations_are_not_recompleted(): void
    {
        foreach ([ReservationStatus::Canceled, ReservationStatus::NoShow] as $status) {
            try {
                $this->completion()->completeReservation($this->reservation(status: $status));
                $this->fail("{$status->value}を完了できました。");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }

        $legacy = $this->reservation(status: ReservationStatus::Completed);
        $this->expectException(ValidationException::class);
        $this->completion()->completeReservation($legacy);
    }

    private function completion(): VisitCompletionService
    {
        return app(VisitCompletionService::class);
    }

    private function reservation(
        ?Customer $customer = null,
        ?Service $service = null,
        ?Staff $staff = null,
        string $startsAt = '2026-09-24 10:00:00',
        ReservationStatus $status = ReservationStatus::Confirmed,
        ReservationPaymentMethod $paymentMethod = ReservationPaymentMethod::Onsite,
    ): Reservation {
        return Reservation::factory()->create([
            'customer_id' => ($customer ?? Customer::factory()->create())->user_id,
            'service_id' => ($service ?? Service::factory()->create(['duration_min' => 60]))->id,
            'staff_id' => ($staff ?? Staff::factory()->create())->user_id,
            'starts_at' => CarbonImmutable::parse($startsAt, 'UTC'),
            'status' => $status,
            'payment_method' => $paymentMethod,
        ]);
    }

    /** @return array{TicketWallet, TicketReservationUsage} */
    private function heldTicket(Reservation $reservation): array
    {
        $wallet = TicketWallet::factory()->create(['customer_id' => $reservation->customer_id]);
        TicketTransaction::factory()->create([
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Grant,
            'delta' => 1,
        ]);
        TicketTransaction::factory()->create([
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::ReserveHold,
            'delta' => -1,
            'reservation_id' => $reservation->id,
            'dedupe_key' => "resv:{$reservation->id}:RESERVE_HOLD",
        ]);
        $usage = TicketReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'ticket_wallet_id' => $wallet->id,
            'no_show_policy' => TicketNoShowPolicy::Restore,
            'status' => TicketReservationUsageStatus::Held,
        ]);

        return [$wallet, $usage];
    }

    /** @return array{Membership, MembershipReservationUsage} */
    private function reservedMembership(Reservation $reservation): array
    {
        $membership = Membership::factory()->create([
            'customer_id' => $reservation->customer_id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
        MembershipUsageTransaction::factory()->create([
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
            'type' => MembershipUsageType::Grant,
            'delta' => 1,
        ]);
        MembershipUsageTransaction::factory()->create([
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
            'type' => MembershipUsageType::Reserve,
            'delta' => -1,
            'reservation_id' => $reservation->id,
            'dedupe_key' => "reserve:{$reservation->id}",
        ]);
        $usage = MembershipReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
            'no_show_policy' => MembershipNoShowPolicy::Consume,
            'status' => MembershipReservationUsageStatus::Reserved,
        ]);

        return [$membership, $usage];
    }

    private function auditCount(string $action, string $entityId): int
    {
        return DB::table('audit_logs')->where('action', $action)->where('entity_id', $entityId)->count();
    }
}
