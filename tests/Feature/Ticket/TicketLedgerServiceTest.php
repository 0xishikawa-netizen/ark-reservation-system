<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Staff;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class TicketLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_available_held_total_and_balance_follow_the_ledger_without_double_subtraction(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();
        $reservation = Reservation::factory()->create(['id' => 1]);

        $service->append(
            $wallet,
            TicketTransactionType::Grant,
            5,
            'grant:direct-summary',
        );

        $this->assertSame(5, $wallet->fresh()->balance);
        $this->assertSame(5, $service->available($wallet));
        $this->assertSame(0, $service->held($wallet));
        $this->assertSame(5, $service->total($wallet));
        $this->assertSame([
            'available' => 5,
            'held' => 0,
            'total' => 5,
        ], $service->summary($wallet));

        $service->append(
            $wallet,
            TicketTransactionType::ReserveHold,
            -1,
            'resv:1:RESERVE_HOLD',
            reservationId: (int) $reservation->id,
        );

        $this->assertSame(4, $wallet->fresh()->balance);
        $this->assertSame(4, $service->available($wallet));
        $this->assertSame(1, $service->held($wallet));
        $this->assertSame(5, $service->total($wallet));

        $service->append(
            $wallet,
            TicketTransactionType::ReserveRelease,
            1,
            'resv:1:RESERVE_RELEASE',
            reservationId: (int) $reservation->id,
        );

        $this->assertSame(5, $wallet->fresh()->balance);
        $this->assertSame(5, $service->available($wallet));
        $this->assertSame(0, $service->held($wallet));
        $this->assertLedgerInvariant($wallet);
    }

    public function test_append_returns_the_existing_transaction_for_the_same_dedupe_key(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();

        $first = $service->append(
            $wallet,
            TicketTransactionType::Grant,
            5,
            'grant:append-dedupe',
        );
        $second = $service->append(
            $wallet,
            TicketTransactionType::Grant,
            5,
            'grant:append-dedupe',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TicketTransaction::query()->count());
        $this->assertSame(5, $wallet->fresh()->balance);
        $this->assertLedgerInvariant($wallet);
    }

    public function test_hold_is_rejected_when_available_balance_is_zero_without_mutation(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();
        $reservation = Reservation::factory()->create();

        try {
            $service->append(
                $wallet,
                TicketTransactionType::ReserveHold,
                -1,
                "resv:{$reservation->id}:RESERVE_HOLD",
                reservationId: (int) $reservation->id,
            );
            $this->fail('残数0の wallet で HOLD が成功しました。');
        } catch (InsufficientTicketBalanceException $exception) {
            $this->assertSame('回数券の残数が不足しています', $exception->getMessage());
        }

        $this->assertSame(0, TicketTransaction::query()->count());
        $this->assertSame(0, $wallet->fresh()->balance);
        $this->assertLedgerInvariant($wallet);
    }

    public function test_insufficient_balance_exception_is_rendered_as_http_409(): void
    {
        Route::get(
            '/_test/ticket/insufficient-balance',
            fn () => throw new InsufficientTicketBalanceException,
        );

        $this->getJson('/_test/ticket/insufficient-balance')
            ->assertStatus(409)
            ->assertJsonPath('message', '回数券の残数が不足しています');
    }

    public function test_revoke_cannot_exceed_available_balance(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();
        $service->append($wallet, TicketTransactionType::Grant, 3, 'grant:revoke-base');

        $this->assertValidationFailure(
            fn () => $service->revoke($wallet, 5, 'too-many', '入力誤りの取消'),
            'ticket',
        );

        $this->assertSame(1, TicketTransaction::query()->count());
        $this->assertSame(3, $wallet->fresh()->balance);
        $this->assertLedgerInvariant($wallet);
    }

    public function test_adjust_rejects_a_negative_result_and_accepts_a_positive_delta(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();
        $service->append($wallet, TicketTransactionType::Grant, 3, 'grant:adjust-base');

        $this->assertValidationFailure(
            fn () => $service->adjust($wallet, -100, 'negative', '棚卸し調整'),
            'ticket',
        );
        $this->assertSame(1, TicketTransaction::query()->count());

        $service->adjust($wallet, 2, 'positive', '棚卸し調整');

        $this->assertSame(5, $service->available($wallet));
        $this->assertSame(5, $wallet->fresh()->balance);
        $this->assertLedgerInvariant($wallet);
    }

    public function test_status_tracks_available_balance_but_expired_is_preserved(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();

        $service->append($wallet, TicketTransactionType::Grant, 1, 'grant:status');
        $this->assertSame(TicketWalletStatus::Active, $wallet->fresh()->status);

        $service->revoke($wallet, 1, 'status', '全数取消');
        $this->assertSame(TicketWalletStatus::Exhausted, $wallet->fresh()->status);
        $this->assertLedgerInvariant($wallet);

        $expired = TicketWallet::factory()->expired()->create();
        $service->append($expired, TicketTransactionType::Grant, 1, 'grant:expired-status');

        $this->assertSame(1, $expired->fresh()->balance);
        $this->assertSame(TicketWalletStatus::Expired, $expired->fresh()->status);
        $this->assertLedgerInvariant($expired);
    }

    public function test_grant_creates_wallet_transaction_and_audit_log_atomically(): void
    {
        $service = $this->ledgerService();
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create([
            'total_count' => 5,
            'validity_days' => 90,
        ]);
        $staff = Staff::factory()->create();

        $wallet = $service->grant(
            $customer,
            $product,
            null,
            'admin-submit-1',
            'キャンペーン付与',
            $staff->user,
        );

        $this->assertTrue($wallet->expires_at->isSameDay(today()->addDays(90)));
        $this->assertSame(5, $wallet->purchased_count);
        $this->assertSame(5, $wallet->balance);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Grant->value,
            'delta' => 5,
            'staff_id' => $staff->user_id,
            'dedupe_key' => 'grant:admin-submit-1',
        ]);
        $this->assertSame(1, $wallet->transactions()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.granted',
            'entity_id' => (string) $wallet->id,
            'actor_user_id' => $staff->user_id,
        ]);
        $this->assertSame(1, $this->auditCount('ticket.granted'));
        $this->assertLedgerInvariant($wallet);
    }

    public function test_grant_is_idempotent_for_the_same_operation_key(): void
    {
        $service = $this->ledgerService();
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => 5]);

        $first = $service->grant(
            $customer,
            $product,
            null,
            'same-submit',
            '管理付与',
        );
        $second = $service->grant(
            $customer,
            $product,
            null,
            'same-submit',
            '管理付与',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TicketWallet::query()->count());
        $this->assertSame(1, TicketTransaction::query()
            ->where('dedupe_key', 'grant:same-submit')
            ->count());
        $this->assertSame(5, $first->fresh()->balance);
        $this->assertSame(1, $this->auditCount('ticket.granted'));
        $this->assertLedgerInvariant($first);
    }

    public function test_management_operations_require_a_reason(): void
    {
        $service = $this->ledgerService();
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();
        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
        ]);
        $service->append($wallet, TicketTransactionType::Grant, 3, 'grant:reason-base');

        $this->assertValidationFailure(
            fn () => $service->grant($customer, $product, null, 'blank-grant'),
            'reason',
        );
        $this->assertValidationFailure(
            fn () => $service->revoke($wallet, 1, 'blank-revoke', ''),
            'reason',
        );
        $this->assertValidationFailure(
            fn () => $service->adjust($wallet, 1, 'blank-adjust', '  '),
            'reason',
        );

        $this->assertSame(1, TicketWallet::query()->count());
        $this->assertSame(1, TicketTransaction::query()->count());
        $this->assertSame(0, $this->auditCount('ticket.granted'));
        $this->assertSame(0, $this->auditCount('ticket.revoked'));
        $this->assertSame(0, $this->auditCount('ticket.adjusted'));
        $this->assertLedgerInvariant($wallet);
    }

    public function test_append_rejects_invalid_delta_for_every_transaction_type(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();
        $invalidDeltas = [
            TicketTransactionType::ReserveHold->value => 1,
            TicketTransactionType::ReserveRelease->value => -1,
            TicketTransactionType::Consume->value => 1,
            TicketTransactionType::Grant->value => 0,
            TicketTransactionType::Purchase->value => 0,
            TicketTransactionType::Revoke->value => 0,
            TicketTransactionType::Expire->value => 0,
            TicketTransactionType::Adjust->value => 0,
        ];

        foreach ($invalidDeltas as $typeValue => $delta) {
            try {
                $service->append(
                    $wallet,
                    TicketTransactionType::from($typeValue),
                    $delta,
                    "invalid:{$typeValue}",
                );
                $this->fail("{$typeValue} の不正 delta が受理されました。");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, TicketTransaction::query()->count());
        $this->assertLedgerInvariant($wallet);
    }

    public function test_revoke_and_adjust_are_idempotent_and_audited_once(): void
    {
        $service = $this->ledgerService();
        $wallet = TicketWallet::factory()->create();
        $service->append($wallet, TicketTransactionType::Grant, 5, 'grant:management-base');

        $firstRevoke = $service->revoke($wallet, 1, 'same-revoke', '取消');
        $secondRevoke = $service->revoke($wallet, 1, 'same-revoke', '取消');
        $firstAdjust = $service->adjust($wallet, 2, 'same-adjust', '調整');
        $secondAdjust = $service->adjust($wallet, 2, 'same-adjust', '調整');

        $this->assertSame($firstRevoke->id, $secondRevoke->id);
        $this->assertSame($firstAdjust->id, $secondAdjust->id);
        $this->assertSame(3, TicketTransaction::query()->count());
        $this->assertSame(6, $wallet->fresh()->balance);
        $this->assertSame(1, $this->auditCount('ticket.revoked'));
        $this->assertSame(1, $this->auditCount('ticket.adjusted'));
        $this->assertLedgerInvariant($wallet);
    }

    private function ledgerService(): TicketLedgerService
    {
        return app(TicketLedgerService::class);
    }

    private function assertLedgerInvariant(TicketWallet $wallet): void
    {
        $this->assertSame(
            (int) $wallet->transactions()->sum('delta'),
            $wallet->fresh()->balance,
        );
    }

    private function assertValidationFailure(Closure $callback, string $key): void
    {
        try {
            $callback();
            $this->fail("{$key} の業務バリデーションが成功扱いになりました。");
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function auditCount(string $action): int
    {
        return (int) DB::table('audit_logs')->where('action', $action)->count();
    }
}
