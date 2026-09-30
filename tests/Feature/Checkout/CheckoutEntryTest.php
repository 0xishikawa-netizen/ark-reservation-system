<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Domain\Accounting\TaxAmountCalculator;
use App\Domain\Business\StoreCalendarService;
use App\Domain\Reporting\DailyReportService;
use App\Domain\Reporting\Excel\ReportWorkbookService;
use App\Domain\Reporting\MonthlyReportService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Models\Booth;
use App\Models\Checkout;
use App\Models\CheckoutTenderAllocation;
use App\Models\Customer;
use App\Models\MembershipPlan;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\TicketProduct;
use App\Models\TicketWallet;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutEntryTest extends TestCase
{
    use RefreshDatabase;

    private TaxCategory $standard;

    private TaxCategory $reduced;

    private PaymentMethod $cash;

    private PaymentMethod $paypay;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 03:00:00', 'UTC'));
        $this->seed(RolePermissionSeeder::class);
        $this->standard = TaxCategory::query()->create(['code' => 'standard', 'name' => '標準税率', 'is_active' => true, 'sort_order' => 1]);
        $this->reduced = TaxCategory::query()->create(['code' => 'reduced', 'name' => '軽減税率', 'is_active' => true, 'sort_order' => 2]);
        TaxRate::query()->create(['tax_category_id' => $this->standard->id, 'rate_bps' => 1000, 'effective_from' => '2019-10-01']);
        TaxRate::query()->create(['tax_category_id' => $this->reduced->id, 'rate_bps' => 800, 'effective_from' => '2019-10-01']);
        $this->cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金', 'is_enabled' => true]);
        $this->paypay = PaymentMethod::factory()->create(['code' => 'paypay', 'name' => 'PayPay', 'is_enabled' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_tax_is_split_from_inclusive_gross_and_floored_per_line(): void
    {
        $calculator = new TaxAmountCalculator;

        $this->assertSame(['net_amount' => 10000, 'tax_amount' => 1000, 'gross_amount' => 11000], $calculator->splitInclusive(11000, 1000));
        $this->assertSame(['net_amount' => 92, 'tax_amount' => 7, 'gross_amount' => 99], $calculator->splitInclusive(99, 800));
        $this->assertSame(['net_amount' => 0, 'tax_amount' => 0, 'gross_amount' => 0], $calculator->splitInclusive(0, 1000));
    }

    public function test_reservation_visit_with_multiple_treatments_staff_nominations_products_and_split_payment(): void
    {
        $admin = $this->admin();
        [$staffA, $staffB] = [Staff::factory()->create(['display_name' => 'A']), Staff::factory()->create(['display_name' => 'B'])];
        $service = Service::factory()->create(['name' => '整体60', 'duration_min' => 60, 'price' => 8800, 'tax_category_id' => $this->standard->id]);
        $product = Product::factory()->create(['name' => '水', 'price' => 110, 'tax_category_id' => $this->reduced->id]);
        $reservation = $this->reservation($service, $staffA);
        // 60分＋15分の2施術を行うため、予約は75分確保（延長済み）とする（Task 11-29 の上限チェック）。
        $reservation->forceFill(['ends_at' => CarbonImmutable::parse('2026-09-15 12:45:00', 'UTC')])->save();

        $this->actingAs($admin)->post(route('admin.reservations.visit', $reservation))->assertRedirect();
        $visit = Visit::query()->where('reservation_id', $reservation->id)->sole();
        $this->assertSame(VisitStatus::Draft, $visit->status);
        $this->assertSame('2026-09-15', $visit->business_date->toDateString());

        $payload = [
            'primary_staff_id' => $staffA->user_id,
            'nominated_staff_ids' => [$staffA->user_id, $staffB->user_id],
            'treatments' => [
                ['service_id' => $service->id, 'actual_minutes' => 60, 'started_at' => '11:30', 'staff' => [
                    ['staff_id' => $staffA->user_id, 'actual_minutes' => 30],
                    ['staff_id' => $staffB->user_id, 'actual_minutes' => 30],
                ]],
                ['service_id' => $service->id, 'actual_minutes' => 15, 'started_at' => '12:30', 'staff' => [
                    ['staff_id' => $staffB->user_id, 'actual_minutes' => 15],
                ]],
            ],
            'lines' => [
                ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1, 'unit_amount' => 8800, 'treatment_index' => 0,
                    'is_staff_allocatable' => true, 'allocations' => [
                        ['staff_id' => $staffA->user_id, 'amount' => 4400], ['staff_id' => $staffB->user_id, 'amount' => 4400],
                    ]],
                ['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 2, 'unit_amount' => 110, 'is_staff_allocatable' => false],
            ],
            'tenders' => [
                ['payment_method_id' => $this->cash->id, 'amount' => 5000, 'retail_amount' => 0],
                ['payment_method_id' => $this->paypay->id, 'amount' => 4020, 'retail_amount' => 220],
            ],
        ];
        $this->actingAs($admin)->put(route('admin.visits.checkout.update', $visit), $payload)->assertSessionHasNoErrors()->assertRedirect();

        $checkout = Checkout::query()->where('visit_id', $visit->id)->sole();
        $this->assertSame(9020, $checkout->total_amount);
        $this->assertSame(8000 + 204, $checkout->subtotal_amount);
        $this->assertSame(800 + 16, $checkout->tax_amount);
        $serviceLine = $checkout->lines()->where('item_type', 'service')->sole();
        $this->assertSame(1000, $serviceLine->tax_rate_bps);
        $this->assertNotNull($serviceLine->visit_treatment_id);
        $this->assertSame(2, $serviceLine->allocations()->whereNotNull('visit_treatment_staff_id')->count());
        $this->assertSame(800, $checkout->lines()->where('item_type', 'product')->value('tax_rate_bps'));

        $this->actingAs($admin)->post(route('admin.visits.complete', $visit))->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        $this->assertSame(CheckoutStatus::Finalized, $checkout->fresh()->status);
        $this->assertSame(1, $visit->visit_sequence);
        $this->assertTrue($visit->staff_requested_at_checkout);
        $this->assertSame($staffA->user_id, $visit->requested_staff_id_at_checkout);
        $this->assertEqualsCanonicalizing([$staffA->user_id, $staffB->user_id], $visit->nominations()->pluck('staff_id')->all());
        $treatment = $visit->treatments()->orderBy('sort_order')->first();
        $this->assertSame('2026-09-15 02:30:00', $treatment->actual_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 03:00:00', $treatment->staffAssignments()->where('staff_id', $staffB->user_id)->value('actual_started_at')->utc()->format('Y-m-d H:i:s'));

        // 完了後の予約指名変更は過去snapshotを変えない。
        $reservation->forceFill(['is_staff_requested' => false])->saveQuietly();
        $this->assertSame(2, $visit->fresh()->nominations()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.finalized', 'entity_id' => (string) $checkout->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'visit.entry_saved', 'entity_id' => (string) $visit->id]);
    }

    public function test_walk_in_visit_without_reservation_can_be_completed_with_checkout(): void
    {
        $admin = $this->admin();
        $staff = Staff::factory()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['duration_min' => 45, 'price' => 6600, 'tax_category_id' => $this->standard->id]);

        $this->actingAs($admin)->post(route('admin.visits.store'), ['customer_id' => $customer->user_id, 'business_date' => '2026-09-15'])->assertRedirect();
        $visit = Visit::query()->whereNull('reservation_id')->sole();
        $this->actingAs($admin)->put(route('admin.visits.checkout.update', $visit), [
            'primary_staff_id' => $staff->user_id,
            'nominated_staff_ids' => [],
            'treatments' => [['service_id' => $service->id, 'actual_minutes' => 45, 'started_at' => '15:00', 'staff' => [
                ['staff_id' => $staff->user_id, 'actual_minutes' => 45],
            ]]],
            'lines' => [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1, 'unit_amount' => 6600,
                'treatment_index' => 0, 'is_staff_allocatable' => true, 'allocations' => [['staff_id' => $staff->user_id, 'amount' => 6600]]]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 6600]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.visits.complete', $visit))->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame(1, $visit->visit_sequence);
        $this->assertFalse($visit->staff_requested_at_checkout);
        $this->assertNotNull($visit->nominations_recorded_at);
        $this->assertSame(CheckoutStatus::Finalized, $visit->checkout->status);
    }

    public function test_visitless_sale_of_product_ticket_and_membership_grants_ticket_once(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price' => 3240, 'tax_category_id' => $this->reduced->id]);
        $ticket = TicketProduct::factory()->create(['total_count' => 4, 'price' => 33000, 'tax_category_id' => $this->standard->id]);
        $plan = MembershipPlan::factory()->create(['price' => 17600, 'tax_category_id' => $this->standard->id]);

        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['customer_id' => $customer->user_id, 'sale_date' => '2026-09-15'])->assertRedirect();
        $checkout = Checkout::query()->whereNull('visit_id')->sole();
        $this->assertSame($customer->user_id, $checkout->customer_id);
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [
                ['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 3240],
                ['item_type' => 'ticket', 'ticket_product_id' => $ticket->id, 'quantity' => 1, 'unit_amount' => 33000],
                ['item_type' => 'membership', 'membership_plan_id' => $plan->id, 'quantity' => 1, 'unit_amount' => 17600],
            ],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 53840]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasNoErrors();

        $this->assertSame(CheckoutStatus::Finalized, $checkout->fresh()->status);
        $this->assertSame(1, TicketWallet::query()->where('customer_id', $customer->user_id)->where('ticket_product_id', $ticket->id)->count());
        $this->assertSame(0, Visit::query()->count());
        $this->assertSame(53840, (int) $checkout->fresh()->total_amount);
    }

    public function test_anonymous_product_sale_is_allowed_but_ticket_requires_customer(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['price' => 110, 'tax_category_id' => $this->reduced->id]);
        $ticket = TicketProduct::factory()->create(['tax_category_id' => $this->standard->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15'])->assertRedirect();
        $checkout = Checkout::query()->sole();

        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [['item_type' => 'ticket', 'ticket_product_id' => $ticket->id, 'quantity' => 1, 'unit_amount' => 1000]],
        ])->assertSessionHasErrors('lines.0.item_type');

        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 110]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 110]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasNoErrors();
        $this->assertSame(CheckoutStatus::Finalized, $checkout->fresh()->status);
    }

    public function test_zero_tender_amount_is_rejected_with_japanese_field_name(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['price' => 110, 'tax_category_id' => $this->reduced->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15'])->assertRedirect();
        $checkout = Checkout::query()->sole();

        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 110]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 0]],
        ])->assertSessionHasErrors('tenders.0.amount');
        $this->assertStringContainsString('支払金額', (string) session('errors')->first('tenders.0.amount'));
    }

    public function test_total_mismatch_and_staff_minutes_mismatch_block_finalization(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['price' => 1100, 'tax_category_id' => $this->standard->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15']);
        $checkout = Checkout::query()->sole();
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 1100]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasErrors('tenders');
        $this->assertSame(CheckoutStatus::Draft, $checkout->fresh()->status);

        $staff = Staff::factory()->create();
        $visit = Visit::factory()->create(['reservation_id' => null, 'status' => VisitStatus::Draft, 'business_date' => '2026-09-15']);
        $this->actingAs($admin)->put(route('admin.visits.checkout.update', $visit), [
            'treatments' => [['actual_minutes' => 60, 'staff' => [['staff_id' => $staff->user_id, 'actual_minutes' => 45]]]],
        ])->assertSessionHasErrors('treatments.0.staff');
    }

    public function test_finalized_checkout_is_locked_and_can_only_be_voided_with_permission(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['price' => 1100, 'tax_category_id' => $this->standard->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15']);
        $checkout = Checkout::query()->sole();
        $body = [
            'lines' => [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 1100]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 1100]],
        ];
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), $body);
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasNoErrors();

        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), $body)->assertSessionHasErrors('checkout');

        $manager = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $manager->givePermissionTo(['admin.access', 'checkouts.manage']);
        $this->actingAs($manager)->post(route('admin.checkouts.void', $checkout), ['reason' => '誤入力'])->assertForbidden();

        $this->actingAs($admin)->post(route('admin.checkouts.void', $checkout), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.checkouts.void', $checkout), ['reason' => '誤入力'])->assertSessionHasNoErrors();
        $this->assertSame(CheckoutStatus::Voided, $checkout->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.voided', 'entity_id' => (string) $checkout->id]);
    }

    public function test_backdated_sale_is_reported_on_its_sale_date_and_long_visit_uses_entered_minutes(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['price' => 2200, 'tax_category_id' => $this->standard->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-14']);
        $checkout = Checkout::query()->sole();
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 2200]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 2200]],
        ]);
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasNoErrors();

        $staff = Staff::factory()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['tax_category_id' => $this->standard->id]);
        $this->actingAs($admin)->post(route('admin.visits.store'), ['customer_id' => $customer->user_id, 'business_date' => '2026-09-14']);
        $visit = Visit::query()->sole();
        $this->actingAs($admin)->put(route('admin.visits.checkout.update', $visit), [
            'primary_staff_id' => $staff->user_id,
            'treatments' => [
                ['service_id' => $service->id, 'actual_minutes' => 30, 'started_at' => '10:00', 'staff' => [['staff_id' => $staff->user_id, 'actual_minutes' => 30]]],
                ['service_id' => $service->id, 'actual_minutes' => 45, 'started_at' => '10:30', 'staff' => [['staff_id' => $staff->user_id, 'actual_minutes' => 45]]],
            ],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.visits.complete', $visit))->assertSessionHasNoErrors();

        $day = app(DailyReportService::class)->forDate('2026-09-14');
        $this->assertSame(2200, $day->paymentDateRevenue);
        $this->assertSame(1, $day->visitCount);
        $this->assertSame(1, $day->longVisitCount);
        $this->assertSame(0, app(DailyReportService::class)->forDate('2026-09-15')->paymentDateRevenue);
    }

    public function test_mixed_checkout_with_multiple_tenders_requires_explicit_retail_allocation(): void
    {
        $admin = $this->admin();
        $service = Service::factory()->create(['price' => 8000, 'tax_category_id' => $this->standard->id]);
        $product = Product::factory()->create(['price' => 3240, 'tax_category_id' => $this->reduced->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15', 'customer_id' => Customer::factory()->create()->user_id]);
        $checkout = Checkout::query()->sole();
        $lines = [
            ['item_type' => 'other', 'item_name' => 'レンタル', 'quantity' => 1, 'unit_amount' => 8000, 'tax_category_id' => $this->standard->id],
            ['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_amount' => 3240],
        ];
        unset($service);

        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), ['lines' => $lines, 'tenders' => [
            ['payment_method_id' => $this->cash->id, 'amount' => 5000],
            ['payment_method_id' => $this->paypay->id, 'amount' => 6240],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(0, CheckoutTenderAllocation::query()->count());
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasErrors('tender_allocations');

        // 物販分の合計が物販明細と合わない配分は確定できない。
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), ['lines' => $lines, 'tenders' => [
            ['payment_method_id' => $this->cash->id, 'amount' => 5000, 'retail_amount' => 0],
            ['payment_method_id' => $this->paypay->id, 'amount' => 6240, 'retail_amount' => 1000],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasErrors('tender_allocations');

        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), ['lines' => $lines, 'tenders' => [
            ['payment_method_id' => $this->cash->id, 'amount' => 5000, 'retail_amount' => 0],
            ['payment_method_id' => $this->paypay->id, 'amount' => 6240, 'retail_amount' => 3240],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $checkout))->assertSessionHasNoErrors();
        $this->assertSame(CheckoutStatus::Finalized, $checkout->fresh()->status);

        $month = app(MonthlyReportService::class)->forMonth(2026, 9, asOfDate: '2026-09-15');
        $methods = collect($month->actualTotals['payment_category_totals'])->keyBy('code');
        $this->assertSame(['treatment' => 5000, 'retail' => 0], ['treatment' => $methods['cash']['treatment_amount'], 'retail' => $methods['cash']['retail_amount']]);
        $this->assertSame(['treatment' => 3000, 'retail' => 3240], ['treatment' => $methods['paypay']['treatment_amount'], 'retail' => $methods['paypay']['retail_amount']]);
        $this->assertSame(0, $methods['paypay']['unallocated_amount']);
        $this->assertSame(['net' => 7273, 'tax' => 727, 'gross' => 8000], $month->actualTotals['sales_split']['treatment']);
        $this->assertSame(['net' => 3000, 'tax' => 240, 'gross' => 3240], $month->actualTotals['sales_split']['retail']);
        $this->assertSame(10273, $month->actualTotals['net_sales']);
        $this->assertSame(11240, $month->actualTotals['gross_sales']);

        $book = app(ReportWorkbookService::class)->build(2026, 9, asOfDate: '2026-09-15')['workbook'];
        $sheet = $book->getSheetByName('月計表');
        $this->assertSame(5000, $sheet->getCell('C17')->getValue());
        $this->assertSame(3000, $sheet->getCell('D17')->getValue());
        $this->assertSame(0, $sheet->getCell('K17')->getValue());
        $this->assertSame(3240, $sheet->getCell('L17')->getValue());
        $this->assertSame(7273, $sheet->getCell('J17')->getValue());
        $this->assertSame(3000, $sheet->getCell('P17')->getValue());
        $this->assertSame(10273, $sheet->getCell('Q17')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_single_category_or_single_tender_allocation_is_filled_only_when_unique(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create(['price' => 1080, 'tax_category_id' => $this->reduced->id]);
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15']);
        $checkout = Checkout::query()->sole();
        $this->actingAs($admin)->put(route('admin.checkouts.update', $checkout), [
            'lines' => [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 2, 'unit_amount' => 1080]],
            'tenders' => [['payment_method_id' => $this->cash->id, 'amount' => 1000], ['payment_method_id' => $this->paypay->id, 'amount' => 1160]],
        ])->assertSessionHasNoErrors();

        $this->assertSame([1000, 1160], CheckoutTenderAllocation::query()->where('allocation_category', 'retail')->orderBy('id')->pluck('amount')->all());
        $this->assertSame(0, CheckoutTenderAllocation::query()->where('allocation_category', 'treatment')->count());
    }

    public function test_staff_without_checkout_permission_cannot_open_or_save(): void
    {
        $staffUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staffUser->givePermissionTo(['admin.access', 'reservations.view']);
        $reservation = $this->reservation(Service::factory()->create(), Staff::factory()->create());

        $this->actingAs($staffUser)->get(route('admin.checkouts.index'))->assertForbidden();
        $this->actingAs($staffUser)->post(route('admin.reservations.visit', $reservation))->assertForbidden();
        $this->assertSame(0, Visit::query()->count());
    }

    /** Task 11-27: 予約から開くと、予約メニュー・時間・担当が施術実績の下書きとして初期表示される。 */
    public function test_opening_a_reservation_prefills_treatment_staff_and_reservation_summary(): void
    {
        $admin = $this->admin();
        $staff = Staff::factory()->create(['display_name' => '担当X']);
        $service = Service::factory()->create(['name' => 'ARKコンディショニング60', 'duration_min' => 60, 'tax_category_id' => $this->standard->id]);
        $reservation = $this->reservation($service, $staff);
        $reservation->forceFill(['ends_at' => CarbonImmutable::parse('2026-09-15 12:35:00', 'UTC'), 'buffer_min' => 5])->save();

        $this->actingAs($admin)->post(route('admin.reservations.visit', $reservation))->assertRedirect();
        // 2回開いても下書きは1つ（冪等）。
        $this->actingAs($admin)->post(route('admin.reservations.visit', $reservation))->assertRedirect();
        $visit = Visit::query()->where('reservation_id', $reservation->id)->sole();
        $treatment = $visit->treatments()->sole();
        $this->assertSame($service->id, $treatment->service_id);
        $this->assertSame(60, $treatment->actual_minutes);
        $this->assertSame([$staff->user_id], $treatment->staffAssignments()->pluck('staff_id')->all());

        $this->actingAs($admin)->get(route('admin.visits.checkout.show', $visit))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visit.treatments.0.started_at', '11:30')
                ->where('visit.treatments.0.staff.0.staff_id', $staff->user_id)
                ->where('visit.reservation.starts_at', '11:30')
                ->where('visit.reservation.ends_at', '12:30')
                ->where('visit.reservation.buffer_min', 5)
                ->where('visit.reservation.service_name', 'ARKコンディショニング60')
                ->where('visit.reservation.staff_name', '担当X')
                ->where('visit.reservation.is_staff_requested', true));
    }

    public function test_entry_pages_render_for_admin(): void
    {
        $admin = $this->admin();
        $reservation = $this->reservation(Service::factory()->create(['tax_category_id' => $this->standard->id]), Staff::factory()->create());
        $this->actingAs($admin)->post(route('admin.reservations.visit', $reservation));
        $visit = Visit::query()->sole();

        $this->actingAs($admin)->get(route('admin.visits.checkout.show', $visit))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Checkouts/Entry')->where('mode', 'visit')->has('taxCategories', 2));
        $this->actingAs($admin)->get(route('admin.checkouts.index', ['date' => '2026-09-15']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Checkouts/Index')->has('rows', 1));
    }

    public function test_entry_screen_offers_business_hours_and_booths_mapped_to_each_service(): void
    {
        $admin = $this->admin();
        $service = Service::factory()->create(['is_active' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $service->booths()->attach($booth->id);
        app(StoreCalendarService::class)->save(
            ['business_date' => '2026-09-15', 'status' => 'special_hours', 'opens_at' => '11:00', 'closes_at' => '19:00'], null,
        );

        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15'])->assertRedirect();
        $checkout = Checkout::query()->whereNull('visit_id')->sole();

        $this->actingAs($admin)->get(route('admin.checkouts.show', $checkout))
            ->assertInertia(fn ($page) => $page
                ->where('businessHours.opens_at', '11:00')
                ->where('businessHours.closes_at', '19:00')
                ->where('services.0.booth_ids', [$booth->id]));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function reservation(Service $service, Staff $staff): Reservation
    {
        return Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => CarbonImmutable::parse('2026-09-15 11:30:00', 'UTC'),
            'status' => ReservationStatus::Confirmed,
            'is_staff_requested' => true,
        ]);
    }
}
