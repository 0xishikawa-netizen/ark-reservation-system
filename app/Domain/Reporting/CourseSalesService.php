<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reporting\SalesBasis;
use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\CourseSalesTarget;
use App\Models\MembershipPlan;
use App\Models\Service;
use App\Models\TicketProduct;
use App\Queries\DailyReportQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * コース別売上・利用・目標（Task 11-25）。
 *
 * コース＝販売・契約上の商品（回数券商品 / 月額プラン / 単発メニュー）。施術分析分類（M/T/A…）とは別概念で、名前から推測しない。
 * - 売上: 決済日基準は会計明細の税込額（受領済み支払の最終受領日＝月計と同じ）。施術日基準は、単発メニューは施術に直結する明細の
 *   来店営業日、回数券・月額は施術日基準の売上配賦（revenue_allocations.recognized_on）。月計の2基準と同じ定義。
 * - 利用: 購入売上とは分ける。回数券・月額は完了来店で消化された予約利用、単発メニューは会計に施術料明細がある完了来店。
 */
final class CourseSalesService
{
    public function __construct(
        private readonly BusinessTime $time,
        private readonly DailyReportQuery $daily,
        private readonly AuditLogger $audit,
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
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->time->timezone());
        $end = $start->addMonth();

        $courses = [];
        foreach ([
            'ticket' => TicketProduct::query()->orderBy('id')->get(['id', 'name', 'is_active']),
            'membership' => MembershipPlan::query()->orderBy('id')->get(['id', 'name', 'is_active']),
            'service' => Service::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'is_active']),
        ] as $type => $records) {
            foreach ($records as $record) {
                $courses["{$type}:{$record->id}"] = [
                    'course_type' => $type, 'course_id' => (int) $record->id, 'name' => (string) $record->name,
                    'is_active' => (bool) $record->is_active,
                    'sales_amount' => 0, 'sales_quantity' => 0, 'usage_count' => 0, 'user_count' => 0,
                    'target_amount' => null, 'target_count' => null,
                ];
            }
        }

        foreach ($this->sales($start, $end, $salesBasis) as $row) {
            $key = "{$row->course_type}:{$row->course_id}";
            if (isset($courses[$key])) {
                $courses[$key]['sales_amount'] += (int) $row->amount;
                $courses[$key]['sales_quantity'] += (int) $row->quantity;
            }
        }
        foreach ($this->usage($start, $end) as $row) {
            $key = "{$row->course_type}:{$row->course_id}";
            if (isset($courses[$key])) {
                $courses[$key]['usage_count'] = (int) $row->usage_count;
                $courses[$key]['user_count'] = (int) $row->user_count;
            }
        }
        foreach (CourseSalesTarget::query()->where('target_month', $start->toDateString())->get() as $target) {
            $key = "{$target->course_type}:{$target->course_id}";
            if (isset($courses[$key])) {
                $courses[$key]['target_amount'] = (int) $target->target_amount;
                $courses[$key]['target_count'] = $target->target_count;
            }
        }

        $rows = [];
        $totals = ['sales_amount' => 0, 'sales_quantity' => 0, 'usage_count' => 0, 'target_amount' => 0, 'target_missing' => 0];
        foreach ($courses as $course) {
            $hasFacts = $course['sales_amount'] > 0 || $course['sales_quantity'] > 0 || $course['usage_count'] > 0 || $course['target_amount'] !== null;
            if (! $course['is_active'] && ! $hasFacts) {
                continue;
            }
            $course['average_unit_amount'] = $course['sales_quantity'] === 0 ? null : intdiv($course['sales_amount'], $course['sales_quantity']);
            $course['difference_amount'] = $course['target_amount'] === null ? null : $course['sales_amount'] - $course['target_amount'];
            $course['achievement_rate'] = $course['target_amount'] === null || $course['target_amount'] === 0 ? null : $course['sales_amount'] / $course['target_amount'];
            $rows[] = $course;
            $totals['sales_amount'] += $course['sales_amount'];
            $totals['sales_quantity'] += $course['sales_quantity'];
            $totals['usage_count'] += $course['usage_count'];
            if ($course['target_amount'] !== null) {
                $totals['target_amount'] += $course['target_amount'];
            }
        }

        return [
            'year' => $year, 'month' => $month, 'month_key' => $start->format('Y-m'), 'sales_basis' => $salesBasis->value,
            'rows' => $rows, 'totals' => $totals,
        ];
    }

    /** コース別の月間売上目標を保存する。NULLは目標を削除する。 */
    public function setTarget(string $month, string $type, int $courseId, ?int $amount, ?int $count, ?Authenticatable $actor): void
    {
        if (! in_array($type, CourseSalesTarget::TYPES, true)) {
            throw new InvalidArgumentException('コース種別が不正です。');
        }
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month, $this->time->timezone());
        if ($monthStart === false || $monthStart->format('Y-m') !== $month) {
            throw new InvalidArgumentException('対象月が不正です。');
        }
        DB::transaction(function () use ($monthStart, $type, $courseId, $amount, $count, $actor): void {
            $key = ['target_month' => $monthStart->toDateString(), 'course_type' => $type, 'course_id' => $courseId];
            $existing = CourseSalesTarget::query()->where($key)->lockForUpdate()->first();
            if ($amount === null) {
                if ($existing !== null) {
                    $existing->delete();
                    $this->audit->log('course_sales_target.cleared', $existing, sprintf('コース目標を削除 %s %s:%d', $monthStart->format('Y-m'), $type, $courseId), $actor);
                }

                return;
            }
            $target = CourseSalesTarget::query()->updateOrCreate($key, [
                'target_amount' => $amount, 'target_count' => $count, 'updated_by' => $actor?->getAuthIdentifier(),
            ]);
            $this->audit->log('course_sales_target.saved', $target, sprintf('コース目標を保存 %s %s:%d %d円', $monthStart->format('Y-m'), $type, $courseId, $amount), $actor);
        });
    }

    /** @return Collection<int, object> */
    private function sales(CarbonImmutable $start, CarbonImmutable $end, SalesBasis $basis)
    {
        $typeSql = "CASE cl.item_type WHEN 'ticket' THEN 'ticket' WHEN 'membership' THEN 'membership' ELSE 'service' END";
        $idSql = "CASE cl.item_type WHEN 'ticket' THEN cl.ticket_product_id WHEN 'membership' THEN cl.membership_plan_id ELSE cl.service_id END";
        if ($basis === SalesBasis::PaymentDate) {
            return DB::table('checkout_lines as cl')
                ->join('checkouts as c', 'c.id', '=', 'cl.checkout_id')
                ->joinSub($this->daily->paidCheckouts($start->utc()->format('Y-m-d H:i:s'), $end->utc()->format('Y-m-d H:i:s')), 'paid', 'paid.checkout_id', '=', 'c.id')
                ->where('c.status', CheckoutStatus::Finalized->value)
                ->whereIn('cl.item_type', ['ticket', 'membership', 'service'])
                ->groupByRaw("{$typeSql}, {$idSql}")
                ->selectRaw("{$typeSql} AS course_type, {$idSql} AS course_id, SUM(cl.gross_amount) AS amount, SUM(cl.quantity) AS quantity")
                ->get();
        }

        $direct = DB::table('checkout_lines as cl')
            ->join('checkouts as c', 'c.id', '=', 'cl.checkout_id')
            ->join('visit_treatments as vt', 'vt.id', '=', 'cl.visit_treatment_id')
            ->join('visits as v', function ($join): void {
                $join->on('v.id', '=', 'vt.visit_id')->on('v.id', '=', 'c.visit_id');
            })
            ->where('c.status', CheckoutStatus::Finalized->value)->where('cl.item_type', 'service')
            ->where('vt.status', VisitTreatmentStatus::Completed->value)->where('v.status', VisitStatus::Completed->value)
            ->where('v.business_date', '>=', $start->toDateString())->where('v.business_date', '<', $end->toDateString())
            ->groupBy('cl.service_id')
            ->selectRaw("'service' AS course_type, cl.service_id AS course_id, SUM(cl.gross_amount) AS amount, SUM(cl.quantity) AS quantity")
            ->get();
        $allocated = DB::table('revenue_allocations as ra')
            ->join('revenue_recognition_contracts as rrc', 'rrc.id', '=', 'ra.revenue_recognition_contract_id')
            ->leftJoin('ticket_wallets as tw', 'tw.id', '=', 'rrc.ticket_wallet_id')
            ->leftJoin('memberships as m', 'm.id', '=', 'rrc.membership_id')
            ->where('ra.recognized_on', '>=', $start->toDateString())->where('ra.recognized_on', '<', $end->toDateString())
            ->groupByRaw('rrc.kind, COALESCE(tw.ticket_product_id, m.membership_plan_id)')
            ->selectRaw('rrc.kind AS course_type, COALESCE(tw.ticket_product_id, m.membership_plan_id) AS course_id, SUM(ra.amount) AS amount, 0 AS quantity')
            ->get();

        return $direct->concat($allocated);
    }

    /** @return Collection<int, object> */
    private function usage(CarbonImmutable $start, CarbonImmutable $end)
    {
        $from = $start->toDateString();
        $to = $end->toDateString();
        $tickets = DB::table('ticket_reservation_usages as u')
            ->join('ticket_wallets as w', 'w.id', '=', 'u.ticket_wallet_id')
            ->join('visits as v', 'v.reservation_id', '=', 'u.reservation_id')
            ->where('u.status', 'consumed')->where('v.status', VisitStatus::Completed->value)
            ->where('v.business_date', '>=', $from)->where('v.business_date', '<', $to)
            ->groupBy('w.ticket_product_id')
            ->selectRaw("'ticket' AS course_type, w.ticket_product_id AS course_id, COUNT(DISTINCT v.id) AS usage_count, COUNT(DISTINCT v.customer_id) AS user_count")
            ->get();
        $memberships = DB::table('membership_reservation_usages as u')
            ->join('memberships as m', 'm.id', '=', 'u.membership_id')
            ->join('visits as v', 'v.reservation_id', '=', 'u.reservation_id')
            ->where('u.status', 'consumed')->where('v.status', VisitStatus::Completed->value)
            ->where('v.business_date', '>=', $from)->where('v.business_date', '<', $to)
            ->groupBy('m.membership_plan_id')
            ->selectRaw("'membership' AS course_type, m.membership_plan_id AS course_id, COUNT(DISTINCT v.id) AS usage_count, COUNT(DISTINCT v.customer_id) AS user_count")
            ->get();
        $services = DB::table('checkout_lines as cl')
            ->join('checkouts as c', 'c.id', '=', 'cl.checkout_id')
            ->join('visits as v', 'v.id', '=', 'c.visit_id')
            ->where('c.status', CheckoutStatus::Finalized->value)->where('cl.item_type', 'service')->whereNotNull('cl.service_id')
            ->where('v.status', VisitStatus::Completed->value)
            ->where('v.business_date', '>=', $from)->where('v.business_date', '<', $to)
            ->groupBy('cl.service_id')
            ->selectRaw("'service' AS course_type, cl.service_id AS course_id, COUNT(DISTINCT v.id) AS usage_count, COUNT(DISTINCT v.customer_id) AS user_count")
            ->get();

        return $tickets->concat($memberships)->concat($services);
    }
}
