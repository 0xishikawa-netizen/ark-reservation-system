<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Ticket\TicketLedgerService;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TicketIdempotencyTest extends TestCase
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

    public function test_retrying_hold_for_the_same_reservation_does_not_decrement_twice(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->walletWithBalance($customer, 3, 'hold-retry');
        $reservation = $this->reservationService()->create($this->input(
            $customer,
            $service,
            $staff,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        ));

        $firstUsage = $this->usage($reservation);
        $retriedUsage = $this->tickets()->hold($reservation);

        $this->assertTrue($firstUsage->is($retriedUsage));
        $this->assertSame(1, TicketReservationUsage::query()->count());
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveHold));
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(2, (int) $wallet->fresh()->balance);
    }

    public function test_cancel_retry_releases_exactly_once(): void
    {
        [$reservation, $wallet] = $this->ticketReservation('cancel-retry');

        $this->reservationService()->cancel($reservation, '初回キャンセル', null);

        try {
            $this->reservationService()->cancel($reservation, '再試行', null);
            $this->fail('キャンセル済み予約への再キャンセルが成功しました。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
    }

    public function test_completed_retry_is_rejected_and_release_and_consume_are_each_written_once(): void
    {
        [$reservation, $wallet] = $this->ticketReservation('complete-retry');

        $this->reservationService()->markCompleted($reservation, null);

        try {
            $this->reservationService()->markCompleted($reservation, null);
            $this->fail('完了済み予約への再完了が成功しました。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::Consume));
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Consumed, $this->usage($reservation)->status);
    }

    public function test_fefo_uses_the_earliest_wallet_then_the_next_wallet(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters('2026-09-20');
        $first = $this->walletWithBalance(
            $customer,
            1,
            'fefo-first',
            expiresAt: '2026-09-30',
        );
        $second = $this->walletWithBalance(
            $customer,
            5,
            'fefo-second',
            expiresAt: '2026-10-31',
        );

        $firstReservation = $this->reservationService()->create($this->input(
            $customer,
            $service,
            $staff,
            CarbonImmutable::parse('2026-09-20 10:00:00'),
        ));
        $secondReservation = $this->reservationService()->create($this->input(
            $customer,
            $service,
            $staff,
            CarbonImmutable::parse('2026-09-20 12:00:00'),
        ));

        $this->assertSame($first->id, $this->usage($firstReservation)->ticket_wallet_id);
        $this->assertSame($second->id, $this->usage($secondReservation)->ticket_wallet_id);
        $this->assertSame(0, $this->ledger()->available($first));
        $this->assertSame(4, $this->ledger()->available($second));
    }

    public function test_expired_or_expired_status_wallets_cannot_be_held(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $this->walletWithBalance(
            $customer,
            1,
            'expired-by-date',
            expiresAt: '2026-09-07',
        );
        $this->walletWithBalance(
            $customer,
            1,
            'expired-by-status',
            expiresAt: '2026-12-31',
            status: TicketWalletStatus::Expired,
        );

        try {
            $this->tickets()->hold($reservation);
            $this->fail('期限切れ wallet だけで HOLD が成功しました。');
        } catch (InsufficientTicketBalanceException $exception) {
            $this->assertSame('利用可能な回数券がありません', $exception->getMessage());
        }

        $this->assertSame(0, TicketReservationUsage::query()->count());
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::ReserveHold));
    }

    public function test_store_request_reports_expired_ticket_balance_as_422(): void
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $this->walletWithBalance(
            $customer,
            1,
            'expired-http',
            expiresAt: '2026-09-07',
        );

        $this->actingAs($customer->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 10:00:00',
                'payment_method' => PaymentMethod::Ticket->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::ReserveHold));
    }

    public function test_preserve_hold_expires_available_then_offsets_a_later_cancel_release(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'starts_at' => CarbonImmutable::parse('2026-10-01 10:00:00'),
        ]);
        $wallet = $this->walletWithBalance(
            $customer,
            5,
            'preserve-hold-boundary',
            expiresAt: '2026-09-09',
        );
        $this->tickets()->hold($reservation);

        $this->assertSame(4, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->ledger()->held($wallet));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $this->artisan('tickets:expire')->assertSuccessful();

        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->ledger()->held($wallet));
        $this->assertSame(TicketWalletStatus::Expired, $wallet->fresh()->status);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Expire->value,
            'delta' => -4,
        ]);

        $this->reservationService()->cancel($reservation, '期限後キャンセル', null);

        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(0, $this->ledger()->held($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::ReserveRelease->value,
            'delta' => 1,
            'dedupe_key' => "resv:{$reservation->id}:RESERVE_RELEASE",
        ]);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Expire->value,
            'delta' => -1,
            'dedupe_key' => "expire:{$wallet->id}:resv:{$reservation->id}",
        ]);
    }

    public function test_reconcile_reports_a_tampered_balance_and_returns_non_zero(): void
    {
        $customer = Customer::factory()->create();
        $wallet = $this->walletWithBalance($customer, 5, 'reconcile-boundary');
        DB::table('ticket_wallets')->where('id', $wallet->id)->update(['balance' => 99]);

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain("balance キャッシュ不一致 wallet#{$wallet->id} customer#{$customer->user_id}")
            ->assertFailed();

        $this->assertSame(99, (int) $wallet->fresh()->balance);
        $this->assertSame(5, $this->ledger()->available($wallet));
    }

    /** @return array{Reservation, TicketWallet} */
    private function ticketReservation(string $key): array
    {
        [$customer, $service, $staff] = $this->bookableMasters();
        $wallet = $this->walletWithBalance($customer, 3, $key);
        $reservation = $this->reservationService()->create($this->input(
            $customer,
            $service,
            $staff,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        ));

        return [$reservation, $wallet];
    }

    /** @return array{Customer, Service, Staff} */
    private function bookableMasters(string $date = '2026-10-01'): array
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
            'work_date' => $date,
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }

    private function input(
        Customer $customer,
        Service $service,
        Staff $staff,
        CarbonImmutable $startsAt,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: $startsAt,
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: PaymentMethod::Ticket,
        );
    }

    private function walletWithBalance(
        Customer $customer,
        int $balance,
        string $key,
        string $expiresAt = '2026-12-31',
        TicketWalletStatus $status = TicketWalletStatus::Active,
    ): TicketWallet {
        $product = TicketProduct::factory()->create(['total_count' => $balance]);
        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => $balance,
            'balance' => 0,
            'expires_at' => $expiresAt,
            'status' => TicketWalletStatus::Active,
        ]);

        $this->ledger()->append(
            wallet: $wallet,
            type: TicketTransactionType::Grant,
            delta: $balance,
            dedupeKey: "grant:{$key}",
        );

        if ($status !== TicketWalletStatus::Active) {
            $wallet->update(['status' => $status]);
        }

        return $wallet;
    }

    private function reservationService(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function tickets(): TicketReservationService
    {
        return app(TicketReservationService::class);
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
        return TicketTransaction::query()->where('type', $type->value)->count();
    }
}
