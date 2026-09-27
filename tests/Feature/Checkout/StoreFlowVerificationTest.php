<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Domain\Reporting\AnnualReportService;
use App\Domain\Reporting\CustomerAnalyticsService;
use App\Domain\Reporting\DailyReportService;
use App\Domain\Reporting\Excel\ReportWorkbookService;
use App\Domain\Reporting\MonthlyReportService;
use App\Domain\Reporting\ReservationAnalysisService;
use App\Domain\Reporting\StaffSalesService;
use App\Domain\Reporting\TimeBandUtilizationService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Models\Booth;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Qualification;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Visit;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 全面検証 IT-19 / IT-20（docs/testing/2026-09-27-full-verification.md）。
 * 予約（ブース自動確定・終了後バッファ）→ 来店・会計（T45＋M15、2名担当、指名、物販、分割払い）→ 確定 →
 * 日計・月計・顧客・スタッフ売上・時間帯・年間・Excel が同じFactを使うこと、
 * キャンセル／無断キャンセル・来店なし物販・会計取消（void）が集計へ正しく反映されることを確認する。
 */
final class StoreFlowVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'ark-jiyugaoka-2026-10-source.xlsx';

    private TaxCategory $standard;

    private PaymentMethod $cash;

    private PaymentMethod $paypay;

    private Service $conditioning;

    private Service $training;

    private Service $massage;

    private Product $product;

    private Booth $trainingA;

    private Staff $staffA;

    private Staff $staffB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // 2026-09-15 08:00 JST。予約は同日14:45。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 23:00:00', 'UTC'));
        config()->set('reservation.allow_admin_free_time', false);
        config()->set('report_export.template_file', self::TEMPLATE);
        app(Settings::class)->set('reservation.slot_minutes', 5, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '21:00');

        $this->standard = TaxCategory::query()->create(['code' => 'standard', 'name' => '標準税率', 'is_active' => true, 'sort_order' => 1]);
        TaxRate::query()->create(['tax_category_id' => $this->standard->id, 'rate_bps' => 1000, 'effective_from' => '2019-10-01']);
        $this->cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金', 'is_enabled' => true]);
        $this->paypay = PaymentMethod::factory()->create(['code' => 'paypay', 'name' => 'PayPay', 'is_enabled' => true]);

        $service = fn (string $name, int $minutes, int $price): Service => Service::factory()->create([
            'name' => $name, 'duration_min' => $minutes, 'price' => $price, 'requires_staff' => true, 'is_active' => true, 'tax_category_id' => $this->standard->id,
        ]);
        $this->conditioning = $service('検証コンディショニング60', 60, 11000);
        $this->training = $service('検証T', 30, 5500);
        $this->massage = $service('検証M', 15, 3300);
        $acupuncture = $service('検証A', 15, 3300);
        $this->product = Product::factory()->create(['name' => '検証物販', 'price' => 1100, 'tax_category_id' => $this->standard->id]);

        $this->trainingA = Booth::factory()->create(['name' => '検証トレーニングA', 'is_active' => true, 'sort_order' => 1]);
        $this->conditioning->booths()->attach($this->trainingA->id);
        $this->staffA = $this->staff('A');
        $this->staffB = $this->staff('B');
        foreach ([$this->conditioning, $this->training, $this->massage, $acupuncture] as $each) {
            $each->staff()->attach([$this->staffA->user_id, $this->staffB->user_id]);
        }
        $license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();
        $acupuncture->qualifications()->attach($license->id);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reservation_to_checkout_to_every_report_uses_the_same_facts_and_void_reverses_sales(): void
    {
        $admin = $this->admin();
        $reservation = $this->book('14:45', 5);
        $this->assertSame([$this->trainingA->id, '15:50'], [$reservation->booth_id, $reservation->ends_at->format('H:i')]);

        // 予約から来店・会計を開く（同じ予約なら同じ下書き）。
        $this->actingAs($admin)->post(route('admin.reservations.visit', $reservation))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.reservations.visit', $reservation))->assertRedirect();
        $visit = Visit::query()->where('reservation_id', $reservation->id)->sole();
        $prefill = $visit->treatments()->sole();
        $this->assertSame([$this->conditioning->id, 60], [$prefill->service_id, (int) $prefill->actual_minutes]);

        // 実施はT45（A）＋M15（B）。施術料11,000はA 75%・B 25%。物販1,100。現金5,000＋PayPay7,100（うち物販1,100）。
        $this->actingAs($admin)->put(route('admin.visits.checkout.update', $visit), [
            'primary_staff_id' => $this->staffA->user_id,
            'nominated_staff_ids' => [$this->staffA->user_id],
            'treatments' => [
                ['service_id' => $this->training->id, 'actual_minutes' => 45, 'started_at' => '14:45', 'booth_id' => $this->trainingA->id,
                    'staff' => [['staff_id' => $this->staffA->user_id, 'actual_minutes' => 45]]],
                ['service_id' => $this->massage->id, 'actual_minutes' => 15, 'started_at' => '15:30', 'booth_id' => $this->trainingA->id,
                    'staff' => [['staff_id' => $this->staffB->user_id, 'actual_minutes' => 15]]],
            ],
            'lines' => [
                ['item_type' => 'service', 'service_id' => $this->conditioning->id, 'quantity' => 1, 'unit_amount' => 11000, 'treatment_index' => 0,
                    'is_staff_allocatable' => true, 'allocations' => [
                        ['staff_id' => $this->staffA->user_id, 'amount' => 8250], ['staff_id' => $this->staffB->user_id, 'amount' => 2750],
                    ]],
                ['item_type' => 'product', 'product_id' => $this->product->id, 'quantity' => 1, 'unit_amount' => 1100, 'is_staff_allocatable' => false],
            ],
            'tenders' => [
                ['payment_method_id' => $this->cash->id, 'amount' => 5000, 'retail_amount' => 0],
                ['payment_method_id' => $this->paypay->id, 'amount' => 7100, 'retail_amount' => 1100],
            ],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.visits.complete', $visit))->assertSessionHasNoErrors();
        // 二重送信しても1件のまま。
        $this->actingAs($admin)->post(route('admin.visits.complete', $visit))->assertSessionHasNoErrors();

        $visit->refresh();
        $checkout = Checkout::query()->where('visit_id', $visit->id)->sole();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        $this->assertSame(CheckoutStatus::Finalized, $checkout->status);
        $this->assertSame([12100, 11000, 1100], [(int) $checkout->total_amount, (int) $checkout->subtotal_amount, (int) $checkout->tax_amount]);
        $this->assertSame([$this->trainingA->id], $visit->treatments()->pluck('booth_id')->unique()->values()->all());
        $this->assertFactIntegrity();

        // 別の予約：キャンセルと無断キャンセル。来店・売上には入らず、予約分析にだけ出る。
        app(ReservationService::class)->cancel($this->book('17:00'), '検証キャンセル', null);
        app(ReservationService::class)->markNoShow($this->book('18:00'), null);
        // 来店なしの物販 2,200（来店数は増えない）。
        $this->storeSale($admin, 2);

        $daily = app(DailyReportService::class)->forDate('2026-09-15');
        $this->assertSame(1, $daily->visitCount);
        $this->assertSame(0, $daily->longVisitCount, '実施60分はロングではない');
        $this->assertSame(1, $daily->firstVisitCount);
        $this->assertSame(14300, $daily->paymentDateRevenue);
        $this->assertSame(['cash' => 5000, 'paypay' => 9300], collect($daily->paymentMethodTotals)->pluck('amount', 'code')->all());
        $this->assertSame(1300, array_sum(array_column($daily->taxTotals, 'tax_amount')));
        $this->assertSame(['net' => 10000, 'tax' => 1000, 'gross' => 11000], $daily->salesSplit['treatment']);
        $this->assertSame(['net' => 3000, 'tax' => 300, 'gross' => 3300], $daily->salesSplit['retail']);

        $monthly = app(MonthlyReportService::class)->forMonth(2026, 9, asOfDate: '2026-09-25');
        $this->assertSame([1, 14300], [$monthly->totals['visit_count'], $monthly->totals['payment_date_revenue']]);

        $analysis = app(ReservationAnalysisService::class)->forMonth(2026, 9)['totals'];
        $this->assertSame([3, 1, 1, 1], [$analysis['reservation_count'], $analysis['completed'], $analysis['canceled'], $analysis['no_show']]);

        $this->assertSame(1, app(CustomerAnalyticsService::class)->forMonth(2026, 9, '2026-09-25')->newCustomers);

        $sales = collect(app(StaffSalesService::class)->forMonth(2026, 9)['staff_rows'])->keyBy('staff_id');
        $this->assertSame(8250, $sales[$this->staffA->user_id]['total_amount']);
        $this->assertSame(8250, $sales[$this->staffA->user_id]['nominated_amount']);
        $this->assertSame(2750, $sales[$this->staffB->user_id]['total_amount']);
        $this->assertSame(11000, $sales->sum('total_amount'), 'スタッフ配分の合計＝施術の税込売上');

        // 14:45〜15:30（A）は12〜15時帯に15分・15〜18時帯に30分、15:30〜15:45（B）は15〜18時帯に15分。
        $bands = collect(app(TimeBandUtilizationService::class)->forMonth(2026, 9, asOfDate: '2026-09-25')['daily_rows'])
            ->filter(fn (array $row): bool => $row['business_date'] === '2026-09-15' && $row['occupied_minutes'] > 0)
            ->mapWithKeys(fn (array $row): array => [$row['staff_id'].':'.$row['band_code'] => $row['occupied_minutes']])->sortKeys()->all();
        $this->assertEquals([
            $this->staffA->user_id.':12_15' => 15,
            $this->staffA->user_id.':15_18' => 30,
            $this->staffB->user_id.':15_18' => 15,
        ], $bands);

        $annual = app(AnnualReportService::class)->forYear(2026, asOfDate: '2026-09-25');
        $this->assertSame([14300, 1], [$annual['months'][8]['payment_date_revenue'], $annual['months'][8]['visit_count']]);
        $fiscal = app(AnnualReportService::class)->forYear(2026, asOfDate: '2026-09-25', period: AnnualReportService::PERIOD_FISCAL);
        $this->assertSame(14300, collect($fiscal['months'])->firstWhere('month_key', '2026-09')['payment_date_revenue']);

        // Excel：6シート。月計表の15日行（17行目）は施術等の現金5,000・施術等計（税抜）10,000、数値の売上金は税抜。
        $export = app(ReportWorkbookService::class)->build(2026, 9, asOfDate: '2026-09-25');
        $book = $export['workbook'];
        $this->assertCount(6, $book->getSheetNames());
        $this->assertSame(5000, $book->getSheetByName('月計表')->getCell('C17')->getValue());
        $this->assertSame(10000, $book->getSheetByName('月計表')->getCell('J17')->getValue());
        $this->assertSame(13000, $book->getSheetByName('数値')->getCell('K18')->getValue());
        $book->disconnectWorksheets();

        // 会計取消：施術売上とスタッフ売上から外れる。来店の事実は残る。監査あり。
        $this->actingAs($admin)->post(route('admin.checkouts.void', $checkout), ['reason' => '検証取消'])->assertSessionHasNoErrors();
        $this->assertSame(CheckoutStatus::Voided, $checkout->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.voided', 'entity_id' => (string) $checkout->id]);
        $afterVoid = app(DailyReportService::class)->forDate('2026-09-15');
        $this->assertSame(2200, $afterVoid->paymentDateRevenue);
        $this->assertSame(1, $afterVoid->visitCount);
        $this->assertSame(0, collect(app(StaffSalesService::class)->forMonth(2026, 9)['staff_rows'])->sum('total_amount'));
        $this->assertSame(2200, app(MonthlyReportService::class)->forMonth(2026, 9, asOfDate: '2026-09-25')->totals['payment_date_revenue']);
    }

    public function test_store_sale_without_visit_counts_sales_but_not_visits(): void
    {
        $this->storeSale($this->admin(), 3);

        $daily = app(DailyReportService::class)->forDate('2026-09-15');
        $this->assertSame([0, 3300], [$daily->visitCount, $daily->paymentDateRevenue]);
        $this->assertSame(0, Visit::query()->count());
        $this->assertSame(0, app(MonthlyReportService::class)->forMonth(2026, 9, asOfDate: '2026-09-25')->totals['visit_count']);
    }

    /** 55：会計・支払・配分・スタッフ売上の金額と参照が一致している。 */
    private function assertFactIntegrity(): void
    {
        $this->assertSame(0, DB::table('checkouts as c')->where('c.status', 'finalized')
            ->whereRaw('c.total_amount <> (select coalesce(sum(t.amount), 0) from checkout_tenders t where t.checkout_id = c.id)')->count(), '会計合計と支払合計');
        $this->assertSame(0, DB::table('checkout_tenders as t')
            ->whereRaw('t.amount <> (select coalesce(sum(a.amount), 0) from checkout_tender_allocations a where a.checkout_tender_id = t.id)')->count(), '支払と支払配分');
        $this->assertSame(0, DB::table('checkout_lines as l')->where('l.is_staff_allocatable', true)
            ->whereRaw('l.gross_amount <> (select coalesce(sum(a.allocated_amount), 0) from staff_revenue_allocations a where a.checkout_line_id = l.id)')->count(), '明細と担当売上配分');
        $this->assertSame(0, DB::table('visit_treatments as t')->leftJoin('visits as v', 'v.id', '=', 't.visit_id')->whereNull('v.id')->count());
        $this->assertSame(0, DB::table('visit_treatments as t')->whereNotNull('t.booth_id')->leftJoin('booths as b', 'b.id', '=', 't.booth_id')->whereNull('b.id')->count());
    }

    private function storeSale(User $admin, int $quantity): void
    {
        $this->actingAs($admin)->post(route('admin.checkouts.store'), ['sale_date' => '2026-09-15'])->assertRedirect();
        $sale = Checkout::query()->whereNull('visit_id')->latest('id')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.checkouts.update', $sale), [
            'lines' => [['item_type' => 'product', 'product_id' => $this->product->id, 'quantity' => $quantity, 'unit_amount' => 1100]],
            'tenders' => [['payment_method_id' => $this->paypay->id, 'amount' => 1100 * $quantity]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.checkouts.finalize', $sale))->assertSessionHasNoErrors();
    }

    private function book(string $time, int $buffer = 0): Reservation
    {
        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) Customer::factory()->create()->user_id,
            serviceId: (int) $this->conditioning->id,
            staffId: (int) $this->staffA->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse("2026-09-15 {$time}:00"),
            source: ReservationSource::Admin,
            actorUserId: null,
            notes: null,
            adminContext: true,
            bufferMin: $buffer,
            isStaffRequested: true,
        ));
    }

    private function staff(string $name): Staff
    {
        $staff = Staff::factory()->create(['display_name' => "検証担当{$name}", 'is_bookable' => true]);
        StaffShift::query()->create(['staff_id' => $staff->user_id, 'work_date' => '2026-09-15', 'start_at' => '10:00:00', 'end_at' => '21:00:00']);

        return $staff;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
