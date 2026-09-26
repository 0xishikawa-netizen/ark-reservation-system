<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\CheckoutService;
use App\Domain\Business\TaxRateService;
use App\Domain\Visit\VisitCompletionService;
use App\Domain\Visit\VisitFactService;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\EmploymentType;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\ServiceAnalysisCategory;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffEmploymentPeriod;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\TaxCategory;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** 手動実行専用。実顧客・実予約は更新せず、識別可能なローカル検証事実だけを作る。 */
final class Phase11OperationalE2ESeeder extends Seeder
{
    public const PREFIX = 'PHASE11_TEST_';

    public const BUSINESS_DATE = '2026-09-15';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Phase 11 E2E seed is restricted to local/testing.');
        }

        DB::transaction(function (): void {
            if (User::query()->where('email', 'phase11_test_a@example.invalid')->exists()) {
                $this->command?->info('PHASE11_TEST_ data already exists; no rows were changed.');

                return;
            }

            $this->call(Phase11MasterSeeder::class);

            $taxCategory = TaxCategory::query()->firstOrCreate(
                ['code' => 'PHASE11_TEST_TAX'],
                ['name' => self::PREFIX.'検証専用税区分', 'is_active' => true, 'sort_order' => 999],
            );
            $taxRate = app(TaxRateService::class)->forDate($taxCategory, self::BUSINESS_DATE)
                ?? app(TaxRateService::class)->save([
                    'tax_category_id' => $taxCategory->id,
                    'rate_bps' => 1000, // この検証 fixture 専用。業務税率を確定する値ではない。
                    'effective_from' => '2026-01-01',
                ], null, null);

            $category = ServiceAnalysisCategory::query()->where('code', 'M')->firstOrFail();
            $service = Service::query()->firstOrCreate(
                ['name' => self::PREFIX.'M検証メニュー'],
                [
                    'duration_min' => 45, 'price' => 11000, 'category' => 'M',
                    'analysis_category_id' => $category->id, 'tax_category_id' => $taxCategory->id,
                    'is_online_bookable' => false, 'requires_staff' => true,
                    'color' => '#546e7a', 'is_active' => true, 'sort_order' => 999,
                ],
            );
            $booth = Booth::query()->firstOrCreate(
                ['name' => self::PREFIX.'ブース'],
                ['sort_order' => 999, 'is_active' => true],
            );
            $staffA = $this->staff('STAFF_A', '担当A');
            $staffB = $this->staff('STAFF_B', '担当B');
            $employee = EmploymentType::query()->where('code', 'employee')->firstOrFail();
            foreach ([$staffA, $staffB] as $staff) {
                StaffEmploymentPeriod::query()->create([
                    'staff_id' => $staff->user_id, 'employment_type_id' => $employee->id,
                    'effective_from' => '2026-01-01',
                ]);
                StaffShift::query()->create([
                    'staff_id' => $staff->user_id, 'work_date' => self::BUSINESS_DATE,
                    'start_at' => '09:00', 'end_at' => '18:00', 'origin' => StaffShift::ORIGIN_MANUAL,
                ]);
            }
            foreach (['2026-09-10', '2026-09-16'] as $date) {
                StaffShift::query()->create([
                    'staff_id' => $staffA->user_id, 'work_date' => $date,
                    'start_at' => '10:00', 'end_at' => '11:00', 'origin' => StaffShift::ORIGIN_MANUAL,
                ]);
            }
            $attendance = StaffAttendance::query()->create([
                'staff_id' => $staffA->user_id, 'business_date' => self::BUSINESS_DATE,
                'clock_in_at' => '2026-09-15 00:00:00', 'clock_out_at' => '2026-09-15 09:00:00',
                'status' => 'confirmed', 'note' => self::PREFIX.' actual attendance',
            ]);
            $attendance->breaks()->create([
                'start_at' => '2026-09-15 03:00:00', 'end_at' => '2026-09-15 04:00:00',
                'note' => self::PREFIX.' 12:00-13:00 break',
            ]);
            foreach ([['15:00', '16:00'], ['12:30', '13:30']] as [$start, $end]) {
                StaffScheduleBlock::query()->create([
                    'staff_id' => $staffA->user_id, 'work_date' => self::BUSINESS_DATE,
                    'start_at' => $start, 'end_at' => $end, 'type' => 'BREAK',
                    'title' => self::PREFIX.'予約不可',
                ]);
            }

            $customers = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
                $user = $this->user($code, '顧客'.$code);
                $customers[$code] = Customer::factory()->create([
                    'user_id' => $user->id, 'kana' => self::PREFIX.$code,
                    'birthday' => $code === 'A' ? '1990-05-01' : null,
                    'gender' => $code === 'A' ? 'female' : null,
                    'note' => self::PREFIX.' local-only E2E', 'created_via' => 'admin',
                ]);
            }

            // 初診予約 snapshot は完了時点で取得する。未来予約は検証実行日からも未来の日付。
            $this->reservation($customers['A'], $staffA, $service, $booth, '2026-10-02 10:00', 45, 'A_NEXT', true);

            $this->completedVisit($customers['D'], $staffA, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-07-10 10:00', 30, 'D_JULY', 5500);
            $this->completedVisit($customers['E'], $staffA, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-09-10 10:00', 30, 'E_FIRST', 5500);
            $this->completedVisit($customers['A'], $staffA, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-09-15 10:00', 45, 'A_FIRST', 11000, requested: true);
            $this->completedVisit($customers['C'], $staffA, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-09-15 11:30', 60, 'C_SHARED', 11000, secondStaff: $staffB, splitPayment: true);
            $this->completedVisit($customers['B'], $staffB, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-09-15 13:00', 75, 'B_LONG', 11000);
            $this->completedVisit($customers['D'], $staffA, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-09-15 16:00', 30, 'D_REEXAM', 5500);
            $this->completedVisit($customers['E'], $staffA, $service, $booth, $taxCategory, $taxRate->rate_bps,
                '2026-09-16 10:00', 30, 'E_SECOND', 5500);
        });
    }

    private function user(string $code, string $label): User
    {
        return User::factory()->create([
            'name' => self::PREFIX.$label,
            'email' => 'phase11_test_'.strtolower($code).'@example.invalid',
            'password' => Str::random(48),
        ]);
    }

    private function staff(string $code, string $label): Staff
    {
        return Staff::factory()->create([
            'user_id' => $this->user($code, $label)->id,
            'display_name' => self::PREFIX.$label,
            'sort_order' => 999,
        ]);
    }

    private function reservation(
        Customer $customer, Staff $staff, Service $service, Booth $booth,
        string $startJst, int $minutes, string $code, bool $requested = false,
    ): Reservation {
        // 予約台帳は既存仕様でJSTの壁時計文字列を保持・表示する。
        // 施術実績のUTC instantとは別に扱い、画面上の予約時刻を9時間ずらさない。
        $start = CarbonImmutable::parse($startJst, 'UTC');

        return Reservation::factory()->create([
            'customer_id' => $customer->user_id, 'staff_id' => $staff->user_id,
            'service_id' => $service->id, 'booth_id' => $booth->id,
            'starts_at' => $start, 'ends_at' => $start->addMinutes($minutes),
            'buffer_min' => 0, 'is_staff_requested' => $requested,
            'notes' => self::PREFIX.$code,
        ]);
    }

    private function completedVisit(
        Customer $customer, Staff $primary, Service $service, Booth $booth,
        TaxCategory $taxCategory, int $taxRateBps, string $startJst, int $minutes,
        string $code, int $gross, ?Staff $secondStaff = null,
        bool $splitPayment = false, bool $requested = false,
    ): void {
        $start = CarbonImmutable::parse($startJst, 'Asia/Tokyo')->utc();
        $previousClock = Carbon::getTestNow();
        Carbon::setTestNow($start->addMinutes($minutes + 15));

        try {
            $reservation = $this->reservation($customer, $primary, $service, $booth, $startJst, $minutes, $code, $requested);
            $facts = app(VisitFactService::class);
            $visit = $facts->createDraft($customer, $reservation, [
                'started_at' => $start, 'primary_staff_id' => $primary->user_id,
                'primary_staff_name_snapshot' => $primary->display_name,
            ]);
            $treatment = $facts->addTreatment($visit, $service, [
                'actual_started_at' => $start, 'actual_ended_at' => $start->addMinutes($minutes),
                'actual_minutes' => $minutes, 'sort_order' => 0,
                'operation_key' => self::PREFIX.$code.'_TREATMENT',
            ]);
            $primaryMinutes = $secondStaff === null ? $minutes : intdiv($minutes, 2);
            $primaryAssignment = $facts->assignStaff($treatment, $primary, [
                'actual_started_at' => $start, 'actual_ended_at' => $start->addMinutes($primaryMinutes),
                'actual_minutes' => $primaryMinutes, 'sort_order' => 0,
            ]);
            $secondAssignment = $secondStaff === null ? null : $facts->assignStaff($treatment, $secondStaff, [
                'actual_started_at' => $start->addMinutes($primaryMinutes),
                'actual_ended_at' => $start->addMinutes($minutes),
                'actual_minutes' => $minutes - $primaryMinutes, 'sort_order' => 1,
            ]);

            $net = intdiv($gross * 10000, 10000 + $taxRateBps);
            $checkoutService = app(CheckoutService::class);
            $checkout = $checkoutService->createDraft($visit, [
                'subtotal_amount' => $net, 'tax_amount' => $gross - $net,
                'total_amount' => $gross, 'currency' => 'jpy',
                'operation_id' => self::PREFIX.$code.'_CHECKOUT',
            ]);
            $line = $checkoutService->addLine($checkout, [
                'item_type' => 'service', 'visit_treatment_id' => $treatment->id,
                'service_id' => $service->id, 'item_name_snapshot' => $service->name,
                'quantity' => 1, 'unit_amount' => $gross, 'tax_rate_bps' => $taxRateBps,
                'net_amount' => $net, 'tax_amount' => $gross - $net,
                'gross_amount' => $gross, 'is_staff_allocatable' => true, 'sort_order' => 0,
                'operation_key' => self::PREFIX.$code.'_LINE',
            ], $taxCategory);
            $primarySales = $secondStaff === null ? $gross : intdiv($gross, 2);
            $checkoutService->allocateStaff($line, $primary, $primarySales, [], $primaryAssignment);
            if ($secondStaff !== null && $secondAssignment !== null) {
                $checkoutService->allocateStaff($line, $secondStaff, $gross - $primarySales, [], $secondAssignment);
            }
            $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
            $checkoutService->addTender($checkout, $cash, $splitPayment ? 5000 : $gross,
                ['operation_key' => self::PREFIX.$code.'_CASH']);
            if ($splitPayment) {
                $paypay = PaymentMethod::query()->where('code', 'paypay')->firstOrFail();
                $checkoutService->addTender($checkout, $paypay, $gross - 5000,
                    ['operation_key' => self::PREFIX.$code.'_PAYPAY']);
            }
            app(VisitCompletionService::class)->completeReservation($reservation);
        } finally {
            Carbon::setTestNow($previousClock);
        }
    }
}
