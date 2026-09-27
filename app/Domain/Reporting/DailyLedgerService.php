<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Visit\VisitStatus;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 日計明細（Task 11-31）。旧Excelの日計表（例：R8.9）に相当する一覧を、予約・来店・施術・会計のFactから
 * 自動で作る。手入力はさせない。1 完了来店 = 1 行。来店なしの店頭販売は別区分の行にする。
 * 月全体を固定本数のクエリで取得する（来店×顧客×スタッフのN+1をしない）。
 */
final class DailyLedgerService
{
    public function __construct(private readonly BusinessTime $businessTime) {}

    /** @return array<string, mixed> */
    public function forMonth(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone());
        $end = $start->endOfMonth()->startOfDay();
        [$from, $to] = [$start->toDateString(), $end->toDateString()];

        $visits = DB::table('visits as v')
            ->join('customers as c', 'c.user_id', '=', 'v.customer_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('reservations as r', 'r.id', '=', 'v.reservation_id')
            ->leftJoin('services as rs', 'rs.id', '=', 'r.service_id')
            ->leftJoin('acquisition_channels as ac', 'ac.id', '=', 'v.first_visit_acquisition_channel_id')
            ->where('v.status', VisitStatus::Completed->value)
            ->whereBetween('v.business_date', [$from, $to])
            ->orderBy('v.business_date')->orderBy('v.completed_at')->orderBy('v.id')
            ->get(['v.id', 'v.business_date', 'v.visit_sequence', 'v.primary_staff_name_snapshot', 'v.first_visit_gender_snapshot',
                'v.first_visit_age_years_snapshot', 'v.future_reservation_exists_at_checkout', 'v.staff_requested_at_checkout',
                'v.checkout_exemption_reason', 'v.reservation_id', 'c.member_no', 'u.name as customer_name',
                'rs.name as reserved_service', 'r.payment_method as reservation_payment', 'ac.name as channel_name']);
        $visitIds = $visits->pluck('id')->all();

        $treatments = DB::table('visit_treatments as t')->leftJoin('booths as b', 'b.id', '=', 't.booth_id')
            ->whereIn('t.visit_id', $visitIds)->orderBy('t.sort_order')->orderBy('t.id')
            ->get(['t.id', 't.visit_id', 't.service_name_snapshot', 't.analysis_category_code_snapshot', 't.actual_minutes', 'b.name as booth_name'])
            ->groupBy('visit_id');
        $staff = DB::table('visit_treatment_staff as s')->join('visit_treatments as t', 't.id', '=', 's.visit_treatment_id')
            ->whereIn('t.visit_id', $visitIds)->orderBy('s.sort_order')
            ->get(['t.visit_id', 't.id as treatment_id', 's.staff_name_snapshot', 's.actual_minutes'])->groupBy('visit_id');
        $nominations = DB::table('visit_staff_nominations as n')->leftJoin('staff', 'staff.user_id', '=', 'n.staff_id')
            ->whereIn('n.visit_id', $visitIds)->get(['n.visit_id', 'staff.display_name'])->groupBy('visit_id');

        $checkouts = DB::table('checkouts')->where('status', CheckoutStatus::Finalized->value)
            ->where(fn ($query) => $query->whereIn('visit_id', $visitIds)
                ->orWhere(fn ($store) => $store->whereNull('visit_id')->whereBetween('sale_date', [$from, $to])))
            ->get(['id', 'visit_id', 'customer_id', 'sale_date', 'subtotal_amount', 'tax_amount', 'total_amount']);
        $checkoutIds = $checkouts->pluck('id')->all();
        $lines = DB::table('checkout_lines')->whereIn('checkout_id', $checkoutIds)->orderBy('sort_order')
            ->get(['checkout_id', 'item_type', 'item_name_snapshot', 'quantity', 'gross_amount', 'net_amount', 'tax_amount'])->groupBy('checkout_id');
        $tenders = DB::table('checkout_tenders as ct')->join('payment_methods as pm', 'pm.id', '=', 'ct.payment_method_id')
            ->whereIn('ct.checkout_id', $checkoutIds)->where('ct.status', 'received')
            ->get(['ct.checkout_id', 'pm.code', 'pm.name', 'ct.amount'])->groupBy('checkout_id');
        $checkoutByVisit = $checkouts->whereNotNull('visit_id')->keyBy('visit_id');

        $sales = static function (object $checkout) use ($lines, $tenders): array {
            $rows = $lines->get($checkout->id, collect());
            $retail = $rows->where('item_type', 'product');

            return [
                'treatment_gross' => (int) $rows->where('item_type', '!=', 'product')->sum('gross_amount'),
                'retail_gross' => (int) $retail->sum('gross_amount'),
                'retail_items' => $retail->map(fn (object $line): string => $line->item_name_snapshot.((int) $line->quantity > 1 ? '×'.$line->quantity : ''))->values()->all(),
                'lines' => $rows->map(fn (object $line): array => ['type' => $line->item_type, 'name' => $line->item_name_snapshot, 'quantity' => (int) $line->quantity,
                    'gross' => (int) $line->gross_amount, 'net' => (int) $line->net_amount, 'tax' => (int) $line->tax_amount])->values()->all(),
                'payments' => $tenders->get($checkout->id, collect())->map(fn (object $tender): array => ['code' => $tender->code, 'name' => $tender->name, 'amount' => (int) $tender->amount])->values()->all(),
                'net' => (int) $checkout->subtotal_amount, 'tax' => (int) $checkout->tax_amount, 'gross' => (int) $checkout->total_amount,
            ];
        };

        $rows = $visits->map(function (object $visit) use ($treatments, $staff, $nominations, $checkoutByVisit, $sales): array {
            $checkout = $checkoutByVisit->get($visit->id);
            $visitTreatments = $treatments->get($visit->id, collect());
            $visitStaff = $staff->get($visit->id, collect());

            return [
                'kind' => 'visit', 'id' => (int) $visit->id, 'date' => (string) $visit->business_date,
                'customer_name' => $visit->customer_name, 'member_no' => $visit->member_no,
                'reserved_menu' => $visit->reserved_service,
                'treatments' => $visitTreatments->map(fn (object $t): array => ['name' => $t->service_name_snapshot, 'category' => $t->analysis_category_code_snapshot,
                    'minutes' => $t->actual_minutes === null ? null : (int) $t->actual_minutes, 'booth' => $t->booth_name])->values()->all(),
                'entitlement' => in_array($visit->reservation_payment, ['ticket', 'membership'], true) ? $visit->reservation_payment : null,
                'primary_staff' => $visit->primary_staff_name_snapshot,
                'actual_staff' => $visitStaff->pluck('staff_name_snapshot')->filter()->unique()->values()->all(),
                'staff_minutes' => $visitStaff->map(fn (object $s): array => ['name' => $s->staff_name_snapshot, 'minutes' => $s->actual_minutes === null ? null : (int) $s->actual_minutes])->values()->all(),
                'nominated' => $nominations->get($visit->id, collect())->pluck('display_name')->filter()->values()->all(),
                'nomination_known' => $visit->staff_requested_at_checkout !== null,
                'next_reservation' => $visit->future_reservation_exists_at_checkout === null ? null : (bool) $visit->future_reservation_exists_at_checkout,
                'is_new' => $visit->visit_sequence === null ? null : (int) $visit->visit_sequence === 1,
                'gender' => (int) $visit->visit_sequence === 1 ? $visit->first_visit_gender_snapshot : null,
                'age_decade' => (int) $visit->visit_sequence === 1 && $visit->first_visit_age_years_snapshot !== null ? intdiv((int) $visit->first_visit_age_years_snapshot, 10) * 10 : null,
                'channel' => (int) $visit->visit_sequence === 1 ? $visit->channel_name : null,
                'checkout_exemption' => $visit->checkout_exemption_reason,
                'sales' => $checkout === null ? null : $sales($checkout),
            ];
        })->values();

        $storeSales = $checkouts->whereNull('visit_id')->sortBy('sale_date')->map(fn (object $checkout): array => [
            'kind' => 'store_sale', 'id' => (int) $checkout->id, 'date' => (string) $checkout->sale_date, 'sales' => $sales($checkout),
        ])->values();

        $sum = static fn ($collection, string $key): int => (int) $collection->sum(fn (array $row): int => (int) ($row['sales'][$key] ?? 0));

        return [
            'year' => $year, 'month' => $month, 'month_key' => $start->format('Y-m'),
            // 旧Excelのシート名（例：R8.9）。表示の補助のみで、正データにはしない。
            'legacy_sheet_name' => 'R'.($year - 2018).'.'.$month,
            'rows' => $rows->all(),
            'store_sales' => $storeSales->all(),
            'totals' => [
                'visit_count' => $rows->count(),
                'visit_gross' => $sum($rows, 'gross'), 'visit_net' => $sum($rows, 'net'), 'visit_tax' => $sum($rows, 'tax'),
                'store_gross' => $sum($storeSales, 'gross'), 'store_net' => $sum($storeSales, 'net'), 'store_tax' => $sum($storeSales, 'tax'),
                'treatment_gross' => $sum($rows, 'treatment_gross') + $sum($storeSales, 'treatment_gross'),
                'retail_gross' => $sum($rows, 'retail_gross') + $sum($storeSales, 'retail_gross'),
                'unsettled_visit_count' => $rows->filter(fn (array $row): bool => $row['sales'] === null && $row['checkout_exemption'] === null)->count(),
            ],
        ];
    }
}
