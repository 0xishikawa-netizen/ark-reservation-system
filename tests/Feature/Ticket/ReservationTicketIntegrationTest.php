<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReservationTicketIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ticket_create_holds_one_ticket_with_reservation_and_slots_in_one_flow(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->grant($customer, 'create-success');

        $reservation = $this->reservations()->create($this->input(
            customer: $customer,
            service: $service,
            staff: $staff,
            paymentMethod: PaymentMethod::Ticket,
            actorUserId: (int) $customer->user_id,
        ));

        $usage = $this->usage($reservation);
        $summary = $this->ledger()->summary($wallet);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(PaymentMethod::Ticket, $reservation->payment_method);
        $this->assertSame(TicketReservationUsageStatus::Held, $usage->status);
        $this->assertSame($wallet->id, $usage->ticket_wallet_id);
        $this->assertSame(1, TicketReservationUsage::query()->count());
        $this->assertSame(2, $summary['available']);
        $this->assertSame(1, $summary['held']);
        $this->assertSame(4, $reservation->resourceSlots()->count());
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'payment_method' => PaymentMethod::Ticket->value,
            'status' => ReservationStatus::Confirmed->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.held',
            'entity_id' => (string) $usage->id,
            'actor_user_id' => $customer->user_id,
        ]);
    }

    public function test_ticket_create_without_balance_rolls_back_reservation_slots_and_usage(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();

        try {
            $this->reservations()->create($this->input(
                customer: $customer,
                service: $service,
                staff: $staff,
                paymentMethod: PaymentMethod::Ticket,
            ));
            $this->fail('回数券残数がない状態で予約が作成されました。');
        } catch (InsufficientTicketBalanceException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, Reservation::query()->count());
        $this->assertDatabaseCount('reservation_resource_slots', 0);
        $this->assertSame(0, TicketReservationUsage::query()->count());
        $this->assertSame(0, TicketTransaction::query()->count());
    }

    public function test_slot_conflict_rolls_back_before_ticket_is_held(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->grant($customer, 'slot-conflict');
        $input = $this->input($customer, $service, $staff);
        $this->reservations()->create($input);

        try {
            $this->reservations()->create($this->input(
                customer: $customer,
                service: $service,
                staff: $staff,
                paymentMethod: PaymentMethod::Ticket,
            ));
            $this->fail('競合する slot に回数券予約が作成されました。');
        } catch (SlotUnavailableException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, Reservation::query()->count());
        $this->assertDatabaseCount('reservation_resource_slots', 4);
        $this->assertSame(0, TicketReservationUsage::query()->count());
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::ReserveHold));
        $this->assertSame(3, $this->ledger()->available($wallet));
    }

    public function test_cancel_releases_ticket_and_slots(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->grant($customer, 'cancel');
        $actor = $customer->user()->firstOrFail();
        $reservation = $this->reservations()->create($this->input(
            $customer,
            $service,
            $staff,
            paymentMethod: PaymentMethod::Ticket,
        ));

        $canceled = $this->reservations()->cancel($reservation, '統合テスト', $actor);

        $this->assertSame(ReservationStatus::Canceled, $canceled->status);
        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertSame(0, $canceled->resourceSlots()->count());
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.released',
            'actor_user_id' => $actor->getAuthIdentifier(),
        ]);
    }

    public function test_mark_completed_consumes_ticket_without_double_decrement(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->grant($customer, 'completed');
        $actor = $customer->user()->firstOrFail();
        $reservation = $this->reservations()->create($this->input(
            $customer,
            $service,
            $staff,
            paymentMethod: PaymentMethod::Ticket,
        ));

        $completed = $this->reservations()->markCompleted($reservation, $actor);

        $this->assertSame(ReservationStatus::Completed, $completed->status);
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Consumed, $this->usage($reservation)->status);
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::Consume));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.consumed',
            'actor_user_id' => $actor->getAuthIdentifier(),
        ]);
    }

    public function test_no_show_restore_snapshot_releases_ticket(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'restore');
        [$reservation, $wallet] = $this->ticketReservation('no-show-restore');

        $noShow = $this->reservations()->markNoShow($reservation, null);

        $this->assertSame(ReservationStatus::NoShow, $noShow->status);
        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::Consume));
    }

    public function test_no_show_consume_snapshot_consumes_ticket(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'consume');
        [$reservation, $wallet, $actor] = $this->ticketReservation('no-show-consume');

        $noShow = $this->reservations()->markNoShow($reservation, $actor);

        $this->assertSame(ReservationStatus::NoShow, $noShow->status);
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Consumed, $this->usage($reservation)->status);
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::Consume));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.consumed',
            'actor_user_id' => $actor->getAuthIdentifier(),
        ]);
    }

    public function test_no_show_policy_change_does_not_apply_retroactively(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'restore');
        [$reservation, $wallet] = $this->ticketReservation('no-show-non-retroactive');
        app(Settings::class)->set('ticket.no_show_policy', 'consume');

        $this->reservations()->markNoShow($reservation, null);

        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::Consume));
    }

    public function test_onsite_reservations_do_not_change_ticket_state_for_any_terminal_operation(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $canceled = $this->reservations()->create($this->input(
            $customer,
            $service,
            $staff,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
        ));
        $completed = $this->reservations()->create($this->input(
            $customer,
            $service,
            $staff,
            startsAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
        ));
        $noShow = $this->reservations()->create($this->input(
            $customer,
            $service,
            $staff,
            startsAt: CarbonImmutable::parse('2026-10-01 14:00:00'),
        ));

        $this->reservations()->cancel($canceled, null, null);
        $this->reservations()->markCompleted($completed, null);
        $this->reservations()->markNoShow($noShow, null);

        $this->assertSame(PaymentMethod::Onsite, $canceled->payment_method);
        $this->assertSame(0, TicketReservationUsage::query()->count());
        $this->assertSame(0, TicketTransaction::query()->count());
    }

    public function test_repeated_terminal_operations_do_not_append_ticket_transactions(): void
    {
        [$cancelReservation] = $this->ticketReservation('cancel-idempotent');
        $this->reservations()->cancel($cancelReservation, null, null);
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));

        try {
            $this->reservations()->cancel($cancelReservation, null, null);
            $this->fail('キャンセル済み予約の再キャンセルが成功しました。');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));

        [$completedReservation] = $this->ticketReservation(
            'completed-idempotent',
            CarbonImmutable::parse('2026-10-01 12:00:00'),
        );
        $this->reservations()->markCompleted($completedReservation, null);
        $transactionCount = TicketTransaction::query()->count();

        try {
            $this->reservations()->markCompleted($completedReservation, null);
            $this->fail('完了済み予約の再完了が成功しました。');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame($transactionCount, TicketTransaction::query()->count());
    }

    public function test_reschedule_moves_only_slots_and_keeps_the_same_ticket_hold(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->grant($customer, 'reschedule');
        $reservation = $this->reservations()->create($this->input(
            $customer,
            $service,
            $staff,
            paymentMethod: PaymentMethod::Ticket,
        ));
        $usage = $this->usage($reservation);
        $transactionCount = TicketTransaction::query()->count();

        $rescheduled = $this->reservations()->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
            expectedVersion: 0,
            actorUserId: null,
            adminContext: false,
        ));

        $freshUsage = $this->usage($reservation);
        $this->assertSame($usage->id, $freshUsage->id);
        $this->assertSame($wallet->id, $freshUsage->ticket_wallet_id);
        $this->assertSame(TicketReservationUsageStatus::Held, $freshUsage->status);
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->ledger()->held($wallet));
        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertSame('2026-10-01 12:00:00', $rescheduled->starts_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('reservation_resource_slots', [
            'reservation_id' => $reservation->id,
            'slot_start' => '2026-10-01 10:00:00',
        ]);
        $this->assertDatabaseHas('reservation_resource_slots', [
            'reservation_id' => $reservation->id,
            'slot_start' => '2026-10-01 12:00:00',
        ]);
    }

    /** @return array{Customer, Service, Staff} */
    private function bookableMasters(): array
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

    private function input(
        Customer $customer,
        Service $service,
        Staff $staff,
        ?CarbonImmutable $startsAt = null,
        PaymentMethod $paymentMethod = PaymentMethod::Onsite,
        ?int $actorUserId = null,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: $startsAt ?? CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: $actorUserId,
            notes: null,
            adminContext: false,
            paymentMethod: $paymentMethod,
        );
    }

    private function grant(Customer $customer, string $operationKey): TicketWallet
    {
        $product = TicketProduct::factory()->create([
            'total_count' => 3,
            'validity_days' => 90,
        ]);

        return $this->ledger()->grant(
            customer: $customer,
            product: $product,
            count: 3,
            operationKey: $operationKey,
            reason: '予約連携テスト用付与',
        );
    }

    /** @return array{Reservation, TicketWallet, User} */
    private function ticketReservation(
        string $operationKey,
        ?CarbonImmutable $startsAt = null,
    ): array {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->grant($customer, $operationKey);
        $reservation = $this->reservations()->create($this->input(
            customer: $customer,
            service: $service,
            staff: $staff,
            startsAt: $startsAt,
            paymentMethod: PaymentMethod::Ticket,
        ));

        return [$reservation, $wallet, $customer->user()->firstOrFail()];
    }

    private function reservations(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function ledger(): TicketLedgerService
    {
        return app(TicketLedgerService::class);
    }

    private function usage(Reservation $reservation): TicketReservationUsage
    {
        return TicketReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
    }

    private function transactionCount(TicketTransactionType $type): int
    {
        return TicketTransaction::query()
            ->where('type', $type->value)
            ->count();
    }
}
