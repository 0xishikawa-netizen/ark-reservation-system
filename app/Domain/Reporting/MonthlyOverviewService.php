<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reporting\SalesBasis;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 月次レポートの概要（Task 11-31）。月計・顧客統計・スタッフ稼働・時間帯・日計明細と同じ Fact／同じ Service を
 * 使って一目で分かる指標だけを並べる（別定義を作らない）。「要確認」は Fact から安全に判定できるものだけ。
 */
final class MonthlyOverviewService
{
    private const SAMPLE_LIMIT = 10;

    public function __construct(
        private readonly MonthlyReportService $monthly,
        private readonly CustomerAnalyticsService $customers,
        private readonly StaffUtilizationService $staff,
        private readonly TimeBandUtilizationService $bands,
        private readonly DailyLedgerService $ledger,
        private readonly BusinessTime $businessTime,
    ) {}

    /** @return array<string, mixed> */
    public function forMonth(int $year, int $month, SalesBasis $basis, ?string $asOfDate = null): array
    {
        $business = $this->monthly->forMonth($year, $month, $basis, $asOfDate);
        $customer = $this->customers->forMonth($year, $month, $asOfDate);
        $staff = $this->staff->forMonth($year, $month, asOfDate: $asOfDate);
        $bands = $this->bands->forMonth($year, $month, asOfDate: $asOfDate);
        $ledger = $this->ledger->forMonth($year, $month)['totals'];
        $totals = $business->totals;
        $ratio = static fn (?int $numerator, ?int $denominator): ?float => $numerator === null || $denominator === null || $denominator === 0 ? null : $numerator / $denominator;

        $sumOrNull = static function (array $rows, string $key): ?int {
            $sum = 0;
            foreach ($rows as $row) {
                if ($row[$key] === null) {
                    return null;
                }
                $sum += (int) $row[$key];
            }

            return $sum;
        };
        $staffRows = $staff['monthly_rows'];
        $occupied = $sumOrNull($staffRows, 'occupied_minutes');
        $dayType = [];
        foreach (['weekday', 'weekend'] as $type) {
            $rows = array_values(array_filter($bands['day_type_rows'], static fn (array $row): bool => $row['day_type'] === $type));
            $dayTypeOccupied = $sumOrNull($rows, 'occupied_minutes');
            $dayType[$type] = [
                'utilization_rate' => $ratio($dayTypeOccupied, $sumOrNull($rows, 'working_minutes')),
                'bookable_rate' => $ratio($dayTypeOccupied, (int) array_sum(array_column($rows, 'bookable_minutes'))),
            ];
        }

        return [
            'year' => $year, 'month' => $month, 'month_key' => sprintf('%04d-%02d', $year, $month),
            'sales_basis' => $basis->value, 'as_of_date' => $customer->asOfDate ?? $asOfDate,
            'sales' => [
                'selected_revenue' => $totals['selected_revenue'],
                'gross' => $totals['gross_sales'], 'net' => $totals['net_sales'], 'tax' => $totals['sales_tax'],
                'treatment_gross' => $totals['sales_split']['treatment']['gross'], 'retail_gross' => $totals['sales_split']['retail']['gross'],
                'target' => $business->target, 'achievement_rate' => $business->progress['achievement_rate'] ?? null,
                // 客単価：来店に紐づく会計（税込）÷完了来店数。来店なしの店頭販売は含めず別に出す。
                'visit_gross' => $ledger['visit_gross'], 'store_gross' => $ledger['store_gross'],
                'average_per_visit' => $ledger['visit_count'] === 0 ? null : intdiv($ledger['visit_gross'], $ledger['visit_count']),
            ],
            'visits' => [
                'visit_count' => $totals['visit_count'], 'long_visit_count' => $totals['long_visit_count'],
                'first_visit_count' => $totals['first_visit_count'],
                'new_customers' => $customer->newCustomers, 'returning_customers' => $customer->returningCustomers,
                'churn_customers' => $customer->churnCustomers,
                'next_reservation_rate' => $business->ratios['future_reservation_rate']->value ?? null,
            ],
            'retention' => [
                'reach_2' => $customer->reach['2']['rate'], 'reach_6' => $customer->reach['6']['rate'], 'reach_10' => $customer->reach['10']['rate'],
            ],
            'utilization' => [
                'utilization_rate' => $ratio($occupied, $sumOrNull($staffRows, 'working_minutes')),
                'bookable_rate' => $ratio($occupied, (int) array_sum(array_column($staffRows, 'bookable_minutes'))),
                'weekday' => $dayType['weekday'], 'weekend' => $dayType['weekend'],
            ],
            'payment_methods' => $business->paymentMethods,
            'attention' => $this->attention($year, $month),
        ];
    }

    /**
     * 要確認：Fact から機械的に判定できる不整合・未入力だけ。件数と、対象への導線（最大10件）を返す。
     *
     * @return list<array{code: string, count: int, items: list<array{label: string, date: string, url: string}>}>
     */
    private function attention(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone());
        [$from, $to] = [$start->toDateString(), $start->endOfMonth()->toDateString()];
        $completed = static fn () => DB::table('visits as v')->join('users as u', 'u.id', '=', 'v.customer_id')
            ->where('v.status', VisitStatus::Completed->value)->whereBetween('v.business_date', [$from, $to]);
        $visitItem = static fn (object $row): array => ['label' => (string) $row->name, 'date' => (string) $row->business_date, 'url' => "/admin/visits/{$row->id}/checkout"];
        $checks = [];

        $checks['accounting_pending'] = $completed()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('checkouts as c')->whereColumn('c.visit_id', 'v.id')->where('c.status', CheckoutStatus::Finalized->value))
            ->whereNull('v.checkout_exemption_reason')->orderBy('v.business_date')->get(['v.id', 'v.business_date', 'u.name'])->map($visitItem);

        $checks['draft_checkout'] = DB::table('checkouts as c')->leftJoin('visits as v', 'v.id', '=', 'c.visit_id')->leftJoin('users as u', 'u.id', '=', 'c.customer_id')
            ->where('c.status', CheckoutStatus::Draft->value)
            ->where(fn ($q) => $q->whereBetween('v.business_date', [$from, $to])->orWhere(fn ($s) => $s->whereNull('c.visit_id')->whereBetween('c.sale_date', [$from, $to])))
            ->get(['c.id', 'c.visit_id', 'c.sale_date', 'v.business_date', 'u.name'])
            ->map(fn (object $row): array => ['label' => (string) ($row->name ?? '-'), 'date' => (string) ($row->business_date ?? $row->sale_date),
                'url' => $row->visit_id !== null ? "/admin/visits/{$row->visit_id}/checkout" : "/admin/checkouts/{$row->id}"]);

        $finalized = static fn () => DB::table('checkouts as c')->leftJoin('visits as v', 'v.id', '=', 'c.visit_id')->leftJoin('users as u', 'u.id', '=', 'c.customer_id')
            ->where('c.status', CheckoutStatus::Finalized->value)
            ->where(fn ($q) => $q->whereBetween('v.business_date', [$from, $to])->orWhere(fn ($s) => $s->whereNull('c.visit_id')->whereBetween('c.sale_date', [$from, $to])));
        $checkoutItem = static fn (object $row): array => ['label' => (string) ($row->name ?? '-'), 'date' => (string) ($row->business_date ?? $row->sale_date), 'url' => "/admin/checkouts/{$row->id}"];

        $checks['payment_mismatch'] = $finalized()
            ->whereRaw("c.total_amount <> COALESCE((SELECT SUM(t.amount) FROM checkout_tenders t WHERE t.checkout_id = c.id AND t.status = 'received'), 0)")
            ->get(['c.id', 'c.sale_date', 'v.business_date', 'u.name'])->map($checkoutItem);

        $checks['allocation_incomplete'] = $finalized()
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('checkout_lines as l')->whereColumn('l.checkout_id', 'c.id')->where('l.is_staff_allocatable', true)
                ->whereRaw('l.gross_amount <> COALESCE((SELECT SUM(a.allocated_amount) FROM staff_revenue_allocations a WHERE a.checkout_line_id = l.id), 0)'))
            ->get(['c.id', 'c.sale_date', 'v.business_date', 'u.name'])->map($checkoutItem);

        $checks['staff_minutes_mismatch'] = $completed()
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('visit_treatments as t')->whereColumn('t.visit_id', 'v.id')
                ->whereRaw('COALESCE(t.actual_minutes, 0) <> COALESCE((SELECT SUM(s.actual_minutes) FROM visit_treatment_staff s WHERE s.visit_treatment_id = t.id), 0)'))
            ->get(['v.id', 'v.business_date', 'u.name'])->map($visitItem);

        $checks['nomination_unknown'] = $completed()->whereNull('v.staff_requested_at_checkout')->get(['v.id', 'v.business_date', 'u.name'])->map($visitItem);

        $checks['reservation_outside_shift'] = DB::table('reservations as r')->join('users as u', 'u.id', '=', 'r.customer_id')
            ->whereIn('r.status', [ReservationStatus::Confirmed->value, ReservationStatus::PendingExternalSync->value])->whereNotNull('r.staff_id')
            ->where('r.starts_at', '>=', $from.' 00:00:00')->where('r.starts_at', '<=', $to.' 23:59:59')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('staff_shifts as sh')->whereColumn('sh.staff_id', 'r.staff_id')
                ->whereRaw('sh.work_date = DATE(r.starts_at)')->whereRaw('sh.start_at <= TIME(r.starts_at)')->whereRaw('sh.end_at >= TIME(r.ends_at)'))
            ->orderBy('r.starts_at')->get(['r.id', 'r.starts_at', 'u.name'])
            ->map(fn (object $row): array => ['label' => (string) $row->name, 'date' => substr((string) $row->starts_at, 0, 16),
                'url' => '/admin/schedule?date='.substr((string) $row->starts_at, 0, 10)."&reservation={$row->id}"]);

        $checks['new_customer_attributes'] = $completed()->where('v.visit_sequence', 1)
            ->where(fn ($q) => $q->whereNull('v.first_visit_gender_snapshot')->orWhereNull('v.first_visit_acquisition_channel_id'))
            ->get(['v.id', 'v.business_date', 'u.name', 'v.customer_id'])
            ->map(fn (object $row): array => ['label' => (string) $row->name, 'date' => (string) $row->business_date, 'url' => "/admin/customers/{$row->customer_id}"]);

        return array_values(array_map(static fn (string $code, $items): array => [
            'code' => $code, 'count' => $items->count(), 'items' => $items->take(self::SAMPLE_LIMIT)->values()->all(),
        ], array_keys($checks), $checks));
    }
}
