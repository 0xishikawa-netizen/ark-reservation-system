<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 店頭購入から予約の押さえ、来店消化、次予約キャンセルによる復元までを通し、回数券の
 * 不正付与・二重消化・戻し忘れ・残数不一致が起きないことを証明する業務シナリオ。
 */
final class TicketLifecycleScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 事故防止: 5回券が購入時に一度だけ付与され、消化後4回、キャンセル後も4回に正しく収束する。
     */
    public function test_store_purchase_hold_consume_cancel_restore_and_reconcile_are_consistent(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
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
            'name' => 'シナリオ5回券',
            'total_count' => 5,
            'price' => 33000,
            'tax_category_id' => $taxCategory->id,
        ]);

        $this->actingAs($admin)->post(route('admin.checkouts.store'), [
            'customer_id' => $customer->user_id,
            'sale_date' => '2026-10-01',
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
        $ledger = app(TicketLedgerService::class);
        $this->assertSame(5, $ledger->available($wallet));
        $this->assertSame(5, (int) $wallet->refresh()->balance);
        $this->assertSame(1, TicketTransaction::query()->where('type', TicketTransactionType::Grant->value)->count());

        [$service, $staff] = $this->bookableMasters();
        $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-20 10:00:00',
            'payment_method' => 'ticket',
        ])->assertSessionHasNoErrors();
        $first = Reservation::query()->where('starts_at', '2026-10-20 10:00:00')->sole();
        $this->assertSame(4, $ledger->available($wallet));
        $this->assertSame(1, $ledger->held($wallet));

        $this->actingAs($admin)
            ->patch("/admin/reservations/{$first->id}/complete", ['exemption_reason' => 'entitlement'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Completed, $first->refresh()->status);
        $this->assertSame(4, $ledger->available($wallet));
        $this->assertSame(0, $ledger->held($wallet));
        $this->assertSame(TicketReservationUsageStatus::Consumed, TicketReservationUsage::query()
            ->where('reservation_id', $first->id)->sole()->status);
        $this->assertSame(1, Visit::query()->where('reservation_id', $first->id)->count());

        $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-20 12:00:00',
            'payment_method' => 'ticket',
        ])->assertSessionHasNoErrors();
        $second = Reservation::query()->where('starts_at', '2026-10-20 12:00:00')->sole();
        $this->assertSame(3, $ledger->available($wallet));
        $this->assertSame(1, $ledger->held($wallet));

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$second->id}", ['reason' => '予定変更'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Canceled, $second->refresh()->status);
        $this->assertSame(4, $ledger->available($wallet));
        $this->assertSame(0, $ledger->held($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, TicketReservationUsage::query()
            ->where('reservation_id', $second->id)->sole()->status);
        $this->assertSame(1, TicketTransaction::query()->where('type', TicketTransactionType::Consume->value)->count());
        $this->assertSame(1, TicketTransaction::query()->where('type', TicketTransactionType::ReserveRelease->value)
            ->where('reservation_id', $second->id)->count());
        $this->artisan('tickets:reconcile')->assertSuccessful();
    }

    /** @return array{Service, Staff} */
    private function bookableMasters(): array
    {
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
            'work_date' => '2026-10-20',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$service, $staff];
    }
}
