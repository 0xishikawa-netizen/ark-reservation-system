<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Domain\Reporting\DailyReportService;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 回数券を販売した会計の取消が、売上だけを消して利用権を残す事故を防ぎ、追記型台帳と
 * 監査証跡を保ったまま利用不能へ収束することを証明する業務シナリオ。
 */
final class CheckoutVoidScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 事故防止: 取消済み会計の回数券を使用できず、取消売上も日計へ残らない。
     */
    public function test_void_revokes_the_granted_ticket_and_excludes_the_checkout_from_daily_revenue(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $customer = Customer::factory()->create();
        $taxCategory = TaxCategory::query()->create([
            'code' => 'standard',
            'name' => '標準税率',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        TaxRate::query()->create([
            'tax_category_id' => $taxCategory->id,
            'rate_bps' => 1000,
            'effective_from' => '2019-10-01',
        ]);
        $cash = PaymentMethod::factory()->create([
            'code' => 'cash',
            'name' => '現金',
            'is_enabled' => true,
        ]);
        $ticket = TicketProduct::factory()->create([
            'name' => '取消確認5回券',
            'total_count' => 5,
            'price' => 33000,
            'tax_category_id' => $taxCategory->id,
        ]);

        $this->actingAs($admin)->post(route('admin.checkouts.store'), [
            'customer_id' => $customer->user_id,
            'sale_date' => '2026-10-05',
        ])->assertRedirect();
        $checkout = Checkout::query()->sole();
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [[
                'item_type' => 'ticket',
                'ticket_product_id' => $ticket->id,
                'quantity' => 1,
                'unit_amount' => 33000,
            ]],
            'tenders' => [[
                'payment_method_id' => $cash->id,
                'amount' => 33000,
            ]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post(route('admin.checkouts.finalize', $checkout))
            ->assertSessionHasNoErrors();

        $wallet = TicketWallet::query()->sole();
        $this->assertSame(5, app(TicketLedgerService::class)->available($wallet));
        $this->assertSame(33000, app(DailyReportService::class)->forDate('2026-10-05')->paymentDateRevenue);

        $this->actingAs($admin)
            ->post(route('admin.checkouts.void', $checkout), ['reason' => '販売入力を取り消すため'])
            ->assertSessionHasNoErrors();

        $this->assertSame(CheckoutStatus::Voided, $checkout->refresh()->status);
        $this->assertDatabaseCount('ticket_wallets', 1);
        $this->assertSame(0, app(TicketLedgerService::class)->available($wallet->refresh()));
        $this->assertSame(0, (int) $wallet->balance);
        $this->assertSame(1, TicketTransaction::query()
            ->where('ticket_wallet_id', $wallet->id)
            ->where('type', TicketTransactionType::Revoke->value)
            ->count());
        $this->assertSame(0, app(DailyReportService::class)->forDate('2026-10-05')->paymentDateRevenue);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'checkout.voided',
            'entity_id' => (string) $checkout->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.revoked',
            'entity_id' => (string) $wallet->id,
        ]);
    }
}
