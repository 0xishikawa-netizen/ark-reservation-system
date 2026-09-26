<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reporting\SalesBasis;
use App\Enums\Visit\VisitStatus;
use App\Queries\DailyReportQuery;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * スタッフ別売上・指名売上（Task 11-22）。正本は確定会計の `staff_revenue_allocations`（税込配分額）。
 *
 * - 指名売上: 来店単位の指名snapshot（visit_staff_nominations）にそのスタッフが含まれる来店の、そのスタッフへの配分額。
 *   両者指名の10,000円（A6,000/B4,000）はA指名6,000・B指名4,000で、配分額以上を二重計上しない。
 * - 非指名売上: 指名が記録済みでそのスタッフが指名されていない来店、および来店なし会計（指名の概念がない）の配分額。
 * - 指名不明: 指名が未記録の旧来店（nominations_recorded_at IS NULL）の配分額。推測で指名/非指名に振り分けない。
 * - 主担当・実担当とは独立（主担当だから指名、担当したから指名とはしない）。
 */
final class StaffSalesService
{
    public function __construct(
        private readonly BusinessTime $businessTime,
        private readonly DailyReportQuery $daily,
    ) {}

    /** @return array<string, mixed> */
    public function forMonth(int $year, int $month, SalesBasis|string $basis = SalesBasis::PaymentDate): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('年月の指定が不正です。');
        }
        $salesBasis = $basis instanceof SalesBasis ? $basis : SalesBasis::tryFrom($basis);
        if ($salesBasis === null) {
            throw new InvalidArgumentException('売上基準の指定が不正です。');
        }
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone());
        $end = $start->addMonth();
        $rows = $this->rows($start, $end, $salesBasis);

        $staff = [];
        $daily = [];
        $totals = ['total_amount' => 0, 'nominated_amount' => 0, 'non_nominated_amount' => 0, 'nomination_unknown_amount' => 0];
        foreach ($rows as $row) {
            $key = $row->staff_id === null ? 'name:'.$row->staff_name : 'id:'.$row->staff_id;
            $staff[$key] ??= [
                'staff_id' => $row->staff_id === null ? null : (int) $row->staff_id,
                'staff_name' => $row->staff_name,
                'total_amount' => 0, 'nominated_amount' => 0, 'non_nominated_amount' => 0, 'nomination_unknown_amount' => 0,
            ];
            $amounts = [
                'total_amount' => (int) $row->total_amount,
                'nominated_amount' => (int) $row->nominated_amount,
                'non_nominated_amount' => (int) $row->non_nominated_amount,
                'nomination_unknown_amount' => (int) $row->unknown_amount,
            ];
            foreach ($amounts as $field => $amount) {
                $staff[$key][$field] += $amount;
                $totals[$field] += $amount;
            }
            $daily[] = ['business_date' => (string) $row->business_date, 'staff_id' => $staff[$key]['staff_id'], 'staff_name' => $row->staff_name, ...$amounts];
        }
        $staffRows = array_values($staff);
        usort($staffRows, static fn (array $a, array $b): int => $b['total_amount'] <=> $a['total_amount'] ?: strcmp((string) $a['staff_name'], (string) $b['staff_name']));
        foreach ($staffRows as &$entry) {
            $known = $entry['total_amount'] - $entry['nomination_unknown_amount'];
            $entry['nominated_share'] = $entry['nomination_unknown_amount'] > 0 || $known === 0 ? null : $entry['nominated_amount'] / $known;
        }
        unset($entry);

        return [
            'year' => $year,
            'month' => $month,
            'month_key' => $start->format('Y-m'),
            'sales_basis' => $salesBasis->value,
            'staff_rows' => $staffRows,
            'daily_rows' => $daily,
            'totals' => $totals,
        ];
    }

    /** @return Collection<int, object> */
    private function rows(CarbonImmutable $start, CarbonImmutable $end, SalesBasis $basis)
    {
        $query = DB::table('staff_revenue_allocations as sra')
            ->join('checkout_lines as cl', 'cl.id', '=', 'sra.checkout_line_id')
            ->join('checkouts as c', 'c.id', '=', 'cl.checkout_id')
            ->leftJoin('visits as v', 'v.id', '=', 'c.visit_id')
            ->leftJoin('visit_staff_nominations as n', function ($join): void {
                $join->on('n.visit_id', '=', 'v.id')->on('n.staff_id', '=', 'sra.staff_id');
            })
            ->where('c.status', CheckoutStatus::Finalized->value);

        if ($basis === SalesBasis::PaymentDate) {
            $startUtc = $start->utc()->format('Y-m-d H:i:s');
            $endUtc = $end->utc()->format('Y-m-d H:i:s');
            // 月計と同じ決済日（受領済み支払の最終受領日時）。
            $query->joinSub($this->daily->paidCheckouts($startUtc, $endUtc), 'paid', 'paid.checkout_id', '=', 'c.id');
            $dateSql = 'DATE(DATE_ADD(paid.paid_at, INTERVAL 9 HOUR))';
        } else {
            // 施術日基準は来店の営業日。来店なし会計（物販のみ等）は施術日がないため対象外。
            $query->where('v.status', VisitStatus::Completed->value)
                ->where('v.business_date', '>=', $start->toDateString())->where('v.business_date', '<', $end->toDateString());
            $dateSql = 'v.business_date';
        }

        return $query
            ->groupByRaw($dateSql)->groupBy('sra.staff_id', 'sra.staff_name_snapshot')
            ->orderByRaw($dateSql)->orderBy('sra.staff_id')
            ->selectRaw("{$dateSql} AS business_date, sra.staff_id, sra.staff_name_snapshot AS staff_name, SUM(sra.allocated_amount) AS total_amount")
            ->selectRaw('COALESCE(SUM(CASE WHEN n.id IS NOT NULL THEN sra.allocated_amount ELSE 0 END), 0) AS nominated_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND v.nominations_recorded_at IS NULL THEN sra.allocated_amount ELSE 0 END), 0) AS unknown_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN n.id IS NULL AND (v.id IS NULL OR v.nominations_recorded_at IS NOT NULL) THEN sra.allocated_amount ELSE 0 END), 0) AS non_nominated_amount')
            ->get();
    }
}
