<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Domain\Accounting\CheckoutService;
use App\Domain\Reporting\DailyReportService;
use App\Domain\Reservation\ReservationService;
use App\Domain\Ticket\TicketLedgerService;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Models\AuditLog;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CheckoutTicketVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_void_revokes_every_unused_granted_wallet_and_is_idempotent(): void
    {
        [$checkout, $wallets, $actor] = $this->finalizedTicketCheckout(quantity: 2);
        $this->assertSame(2000, app(DailyReportService::class)->forDate('2026-09-15')->paymentDateRevenue);

        $service = app(CheckoutService::class);
        $service->void($checkout, '入力誤り', $actor);
        $service->void($checkout, '二重送信', $actor);

        $this->assertSame(CheckoutStatus::Voided, $checkout->fresh()->status);
        $this->assertSame(0, app(DailyReportService::class)->forDate('2026-09-15')->paymentDateRevenue);
        $this->assertDatabaseCount('ticket_wallets', 2);
        $this->assertSame(2, TicketTransaction::query()->where('type', TicketTransactionType::Revoke->value)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'checkout.voided')
            ->where('entity_id', (string) $checkout->id)
            ->count());

        foreach ($wallets as $wallet) {
            $fresh = $wallet->fresh();
            $this->assertSame(0, app(TicketLedgerService::class)->available($fresh));
            $this->assertSame(0, $fresh->balance);
            $this->assertSame(TicketWalletStatus::Exhausted, $fresh->status);
            $this->assertDatabaseHas('ticket_transactions', [
                'ticket_wallet_id' => $fresh->id,
                'type' => TicketTransactionType::Revoke->value,
                'dedupe_key' => "revoke:checkout-void:{$checkout->id}:{$fresh->id}",
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'ticket.revoked',
                'entity_id' => (string) $fresh->id,
            ]);
        }

        $this->artisan('tickets:reconcile')->assertSuccessful();
    }

    public function test_void_is_rejected_when_a_granted_wallet_is_held_by_a_reservation(): void
    {
        [$checkout, $wallets, $actor] = $this->finalizedTicketCheckout(quantity: 2);
        $this->markWalletHeld($wallets->firstOrFail());

        $this->assertVoidRejectedWithoutRevocation($checkout, $actor);
    }

    public function test_void_is_rejected_when_a_granted_wallet_was_consumed(): void
    {
        [$checkout, $wallets, $actor] = $this->finalizedTicketCheckout(quantity: 2);
        $wallet = $wallets->firstOrFail();
        $reservation = Reservation::factory()->create(['customer_id' => $wallet->customer_id]);
        $ledger = app(TicketLedgerService::class);
        $ledger->append($wallet, TicketTransactionType::ReserveHold, -1, "resv:{$reservation->id}:RESERVE_HOLD", $reservation->id);
        $ledger->append($wallet, TicketTransactionType::ReserveRelease, 1, "resv:{$reservation->id}:RESERVE_RELEASE", $reservation->id);
        $ledger->append($wallet, TicketTransactionType::Consume, -1, "resv:{$reservation->id}:CONSUME", $reservation->id);
        TicketReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'ticket_wallet_id' => $wallet->id,
            'status' => TicketReservationUsageStatus::Consumed,
            'consumed_at' => now(),
        ]);

        $this->assertVoidRejectedWithoutRevocation($checkout, $actor);
    }

    public function test_void_succeeds_after_a_reservation_releases_the_granted_ticket(): void
    {
        [$checkout, $wallets, $actor] = $this->finalizedTicketCheckout();
        $wallet = $wallets->firstOrFail();
        $reservation = Reservation::factory()->create([
            'customer_id' => $wallet->customer_id,
            'payment_method' => PaymentMethod::Ticket,
            'status' => ReservationStatus::Confirmed,
        ]);

        app(TicketReservationService::class)->hold($reservation, $actor);
        $this->assertSame(3, app(TicketLedgerService::class)->available($wallet));

        app(ReservationService::class)->cancel($reservation, '予約キャンセル', $actor);

        $this->assertSame(TicketReservationUsageStatus::Released, TicketReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->value('status'));
        $this->assertSame(4, app(TicketLedgerService::class)->available($wallet));

        app(CheckoutService::class)->void($checkout, '販売取消', $actor);

        $this->assertSame(CheckoutStatus::Voided, $checkout->fresh()->status);
        $this->assertSame(0, app(TicketLedgerService::class)->available($wallet->fresh()));
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Revoke->value,
            'delta' => -4,
            'dedupe_key' => "revoke:checkout-void:{$checkout->id}:{$wallet->id}",
        ]);
    }

    /** @return array{Checkout, Collection<int, TicketWallet>, User} */
    private function finalizedTicketCheckout(int $quantity = 1): array
    {
        $customer = Customer::factory()->create();
        $actor = User::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => 4, 'price' => 1000]);
        $total = 1000 * $quantity;
        $checkout = Checkout::factory()->create([
            'visit_id' => null,
            'customer_id' => $customer->user_id,
            'sale_date' => '2026-09-15',
            'status' => CheckoutStatus::Draft,
            'subtotal_amount' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
        ]);
        CheckoutLine::factory()->create([
            'checkout_id' => $checkout->id,
            'item_type' => 'ticket',
            'ticket_product_id' => $product->id,
            'item_name_snapshot' => $product->name,
            'quantity' => $quantity,
            'unit_amount' => 1000,
            'net_amount' => $total,
            'tax_amount' => 0,
            'gross_amount' => $total,
            'is_staff_allocatable' => false,
        ]);
        CheckoutTender::factory()->create([
            'checkout_id' => $checkout->id,
            'amount' => $total,
            'received_at' => '2026-09-15 03:00:00',
        ]);

        app(CheckoutService::class)->finalize($checkout, $actor);

        return [
            $checkout->fresh(),
            TicketWallet::query()->where('customer_id', $customer->user_id)->orderBy('id')->get(),
            $actor,
        ];
    }

    private function markWalletHeld(TicketWallet $wallet): void
    {
        $reservation = Reservation::factory()->create(['customer_id' => $wallet->customer_id]);
        app(TicketLedgerService::class)->append(
            $wallet,
            TicketTransactionType::ReserveHold,
            -1,
            "resv:{$reservation->id}:RESERVE_HOLD",
            $reservation->id,
        );
        TicketReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'ticket_wallet_id' => $wallet->id,
            'no_show_policy' => TicketNoShowPolicy::Restore,
            'status' => TicketReservationUsageStatus::Held,
        ]);
    }

    private function assertVoidRejectedWithoutRevocation(Checkout $checkout, User $actor): void
    {
        try {
            app(CheckoutService::class)->void($checkout, '取消テスト', $actor);
            $this->fail('予約で使用中または使用済みの回数券を付与した会計を取り消せました。');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('messages.checkout_entry.void_ticket_in_use'),
                $exception->errors()['checkout'][0] ?? null,
            );
        }

        $this->assertSame(CheckoutStatus::Finalized, $checkout->fresh()->status);
        $this->assertDatabaseCount('ticket_wallets', 2);
        $this->assertSame(0, TicketTransaction::query()->where('type', TicketTransactionType::Revoke->value)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'checkout.voided',
            'entity_id' => (string) $checkout->id,
        ]);
    }
}
