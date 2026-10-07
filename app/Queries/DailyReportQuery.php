<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** 集計粒度ごとにSQLを分離し、複数明細JOINによる掛け算を防ぐ。 */
final class DailyReportQuery
{
    /** @return array<string, mixed> */
    public function fetch(CarbonImmutable $businessDate): array
    {
        return $this->fetchRange($businessDate, $businessDate->addDay())[$businessDate->toDateString()]
            ?? $this->emptyFacts();
    }

    /**
     * 月計でも日計と同じSQL定義を使う。endExclusiveは半開区間の終端。
     * 日数に依存せず8本の集約SQLで期間全体を取得する。
     *
     * @return array<string, array<string, mixed>> business_dateをkeyとする日次facts
     */
    public function fetchRange(CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $startDate = $start->toDateString();
        $endDate = $endExclusive->toDateString();
        $startUtc = $start->startOfDay()->utc()->format('Y-m-d H:i:s');
        $endUtc = $endExclusive->startOfDay()->utc()->format('Y-m-d H:i:s');
        $facts = [];

        foreach ($this->visitMetrics($startDate, $endDate) as $date => $value) {
            $facts[$date] = $this->emptyFacts();
            $facts[$date]['visits'] = $value;
        }
        foreach ($this->durationMetrics($startDate, $endDate) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['durations'] = $value;
        }
        foreach ($this->categoryMetrics($startDate, $endDate) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['categories'] = $value;
        }
        foreach ($this->unknownCategoryVisitCounts($startDate, $endDate) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['unknown_category_visits'] = $value;
        }
        foreach ($this->paymentMethodMetrics($startUtc, $endUtc) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['payment_methods'] = $value;
        }
        foreach ($this->taxMetrics($startUtc, $endUtc) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['taxes'] = $value;
        }
        foreach ($this->salesSplitMetrics($startUtc, $endUtc) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['sales_split'] = $value;
        }
        foreach ($this->paymentCategoryMetrics($startUtc, $endUtc) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['payment_categories'] = $value;
        }
        foreach ($this->directTreatmentRevenue($startDate, $endDate) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['direct_treatment_revenue'] = $value;
        }
        foreach ($this->allocatedTreatmentRevenue($startDate, $endDate) as $date => $value) {
            $facts[$date] ??= $this->emptyFacts();
            $facts[$date]['allocated_treatment_revenue'] = $value;
        }

        ksort($facts);

        return $facts;
    }

    /** @return array<string, array<string, int>> */
    private function visitMetrics(string $startDate, string $endDate): array
    {
        return $this->completedVisits($startDate, $endDate)
            ->leftJoin('checkouts as c', 'c.visit_id', '=', 'v.id')
            ->groupBy('v.business_date')
            ->selectRaw('v.business_date, COUNT(*) AS visit_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN v.future_reservation_exists_at_checkout = 1 THEN 1 ELSE 0 END), 0) AS future_reservation_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN v.future_reservation_exists_at_checkout IS NULL THEN 1 ELSE 0 END), 0) AS future_reservation_unknown_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN v.visit_sequence = 1 THEN 1 ELSE 0 END), 0) AS first_visit_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN v.visit_sequence = 1 AND v.future_reservation_exists_at_checkout = 1 THEN 1 ELSE 0 END), 0) AS first_visit_reservation_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN v.visit_sequence = 1 AND v.future_reservation_exists_at_checkout IS NULL THEN 1 ELSE 0 END), 0) AS first_visit_reservation_unknown_count')
            // 会計なし完了の理由（無料・事前決済済み・回数券/月額利用）がある来店は会計待ちに数えない（Task 11-27）。
            ->selectRaw('COALESCE(SUM(CASE WHEN c.id IS NULL AND v.checkout_exemption_reason IS NULL THEN 1 ELSE 0 END), 0) AS accounting_pending_visit_count')
            // 月次概要と同じ客単価の分子。決済日ではなく、来店に紐づく確定会計の税込合計を使う。
            ->selectRaw('COALESCE(SUM(CASE WHEN c.status = ? THEN c.total_amount ELSE 0 END), 0) AS visit_gross', [CheckoutStatus::Finalized->value])
            ->get()->mapWithKeys(static fn (object $row): array => [(string) $row->business_date => [
                'visit_count' => (int) $row->visit_count,
                'future_reservation_count' => (int) $row->future_reservation_count,
                'future_reservation_unknown_count' => (int) $row->future_reservation_unknown_count,
                'first_visit_count' => (int) $row->first_visit_count,
                'first_visit_reservation_count' => (int) $row->first_visit_reservation_count,
                'first_visit_reservation_unknown_count' => (int) $row->first_visit_reservation_unknown_count,
                'accounting_pending_visit_count' => (int) $row->accounting_pending_visit_count,
                'visit_gross' => (int) $row->visit_gross,
            ]])->all();
    }

    /** @return array<string, array{long_visit_count:int,unknown_treatment_minutes_visit_count:int}> */
    private function durationMetrics(string $startDate, string $endDate): array
    {
        $perVisit = $this->completedVisits($startDate, $endDate)
            ->leftJoin('visit_treatments as vt', function ($join): void {
                $join->on('vt.visit_id', '=', 'v.id')->where('vt.status', VisitTreatmentStatus::Completed->value);
            })
            ->groupBy('v.business_date', 'v.id')
            ->selectRaw('v.business_date, v.id, COUNT(vt.id) AS treatment_count, COUNT(vt.actual_minutes) AS known_minutes_count, COALESCE(SUM(vt.actual_minutes), 0) AS total_minutes');

        return DB::query()->fromSub($perVisit, 'visit_durations')
            ->groupBy('business_date')->selectRaw('business_date')
            ->selectRaw('SUM(CASE WHEN treatment_count > 0 AND treatment_count = known_minutes_count AND total_minutes > 60 THEN 1 ELSE 0 END) AS long_visit_count')
            ->selectRaw('SUM(CASE WHEN treatment_count = 0 OR treatment_count <> known_minutes_count THEN 1 ELSE 0 END) AS unknown_count')
            ->get()->mapWithKeys(static fn (object $row): array => [(string) $row->business_date => [
                'long_visit_count' => (int) $row->long_visit_count,
                'unknown_treatment_minutes_visit_count' => (int) $row->unknown_count,
            ]])->all();
    }

    /** @return array<string, list<array{code:string,name:string|null,visit_count:int}>> */
    private function categoryMetrics(string $startDate, string $endDate): array
    {
        $perVisit = $this->completedVisits($startDate, $endDate)
            ->join('visit_treatments as vt', function ($join): void {
                $join->on('vt.visit_id', '=', 'v.id')->where('vt.status', VisitTreatmentStatus::Completed->value);
            })->whereNotNull('vt.analysis_category_code_snapshot')->distinct()
            ->select(['v.business_date', 'v.id', 'vt.analysis_category_code_snapshot', 'vt.analysis_category_name_snapshot']);

        return DB::query()->fromSub($perVisit, 'visit_categories')
            ->groupBy('business_date', 'analysis_category_code_snapshot', 'analysis_category_name_snapshot')
            ->orderBy('business_date')->orderBy('analysis_category_code_snapshot')
            ->selectRaw('business_date, analysis_category_code_snapshot AS code, analysis_category_name_snapshot AS name, COUNT(*) AS visit_count')
            ->get()->groupBy('business_date')->map(static fn ($rows): array => $rows->map(static fn (object $row): array => [
                'code' => (string) $row->code, 'name' => $row->name === null ? null : (string) $row->name,
                'visit_count' => (int) $row->visit_count,
            ])->all())->all();
    }

    /** @return array<string, int> */
    private function unknownCategoryVisitCounts(string $startDate, string $endDate): array
    {
        return $this->completedVisits($startDate, $endDate)
            ->where(function (Builder $query): void {
                $query->whereNotExists(function (Builder $treatments): void {
                    $treatments->selectRaw('1')->from('visit_treatments as known_vt')
                        ->whereColumn('known_vt.visit_id', 'v.id')->where('known_vt.status', VisitTreatmentStatus::Completed->value);
                })->orWhereExists(function (Builder $treatments): void {
                    $treatments->selectRaw('1')->from('visit_treatments as unknown_vt')
                        ->whereColumn('unknown_vt.visit_id', 'v.id')->where('unknown_vt.status', VisitTreatmentStatus::Completed->value)
                        ->whereNull('unknown_vt.analysis_category_code_snapshot');
                });
            })->groupBy('v.business_date')->selectRaw('v.business_date, COUNT(*) AS unknown_count')
            ->pluck('unknown_count', 'business_date')->map(static fn (mixed $value): int => (int) $value)->all();
    }

    /** @return array<string, list<array{payment_method_id:int,code:string,name:string,amount:int}>> */
    private function paymentMethodMetrics(string $startUtc, string $endUtc): array
    {
        $dateSql = 'DATE(DATE_ADD(ct.received_at, INTERVAL 9 HOUR))';

        return DB::table('checkout_tenders as ct')->join('checkouts as c', 'c.id', '=', 'ct.checkout_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'ct.payment_method_id')
            ->where('c.status', CheckoutStatus::Finalized->value)->where('ct.status', CheckoutTenderStatus::Received->value)
            ->where('ct.received_at', '>=', $startUtc)->where('ct.received_at', '<', $endUtc)
            ->groupByRaw($dateSql)->groupBy('pm.id', 'pm.code', 'pm.name', 'pm.display_order')
            ->orderByRaw($dateSql)->orderBy('pm.display_order')->orderBy('pm.id')
            ->selectRaw("{$dateSql} AS business_date, pm.id AS payment_method_id, pm.code, pm.name, SUM(ct.amount) AS amount")
            ->get()->groupBy('business_date')->map(static fn ($rows): array => $rows->map(static fn (object $row): array => [
                'payment_method_id' => (int) $row->payment_method_id, 'code' => (string) $row->code,
                'name' => (string) $row->name, 'amount' => (int) $row->amount,
            ])->all())->all();
    }

    /**
     * 確定会計の決済日（受領済み支払の最終受領日時）。支払が期間内にある会計だけを候補にし、
     * 会計の全支払の最終受領が期間内のものを返す。税・税抜・物販の決済日基準はこの日付で揃える。
     */
    public function paidCheckouts(string $startUtc, string $endUtc): Builder
    {
        $candidates = DB::table('checkout_tenders')->select('checkout_id')
            ->where('status', CheckoutTenderStatus::Received->value)
            ->where('received_at', '>=', $startUtc)->where('received_at', '<', $endUtc);

        return DB::table('checkout_tenders as pt')
            ->where('pt.status', CheckoutTenderStatus::Received->value)
            ->whereIn('pt.checkout_id', $candidates)
            ->groupBy('pt.checkout_id')
            ->selectRaw('pt.checkout_id, MAX(pt.received_at) AS paid_at')
            ->havingRaw('MAX(pt.received_at) >= ? AND MAX(pt.received_at) < ?', [$startUtc, $endUtc]);
    }

    /** @return array<string, list<array<string, int|string|null>>> */
    private function taxMetrics(string $startUtc, string $endUtc): array
    {
        $dateSql = 'DATE(DATE_ADD(paid.paid_at, INTERVAL 9 HOUR))';

        return DB::table('checkouts as c')
            ->joinSub($this->paidCheckouts($startUtc, $endUtc), 'paid', 'paid.checkout_id', '=', 'c.id')
            ->join('checkout_lines as cl', 'cl.checkout_id', '=', 'c.id')
            ->where('c.status', CheckoutStatus::Finalized->value)
            ->groupByRaw($dateSql)->groupBy('cl.tax_category_code_snapshot', 'cl.tax_category_name_snapshot', 'cl.tax_rate_bps')
            ->orderByRaw($dateSql)->orderBy('cl.tax_rate_bps')->orderBy('cl.tax_category_code_snapshot')
            ->selectRaw("{$dateSql} AS business_date, cl.tax_category_code_snapshot, cl.tax_category_name_snapshot, cl.tax_rate_bps, SUM(cl.net_amount) AS net_amount, SUM(cl.tax_amount) AS tax_amount, SUM(cl.gross_amount) AS gross_amount, COUNT(*) AS line_count")
            ->get()->groupBy('business_date')->map(static fn ($rows): array => $rows->map(static fn (object $row): array => [
                'tax_category_code' => $row->tax_category_code_snapshot === null ? null : (string) $row->tax_category_code_snapshot,
                'tax_category_name' => $row->tax_category_name_snapshot === null ? null : (string) $row->tax_category_name_snapshot,
                'tax_rate_bps' => $row->tax_rate_bps === null ? null : (int) $row->tax_rate_bps,
                'net_amount' => (int) $row->net_amount, 'tax_amount' => (int) $row->tax_amount,
                'gross_amount' => (int) $row->gross_amount, 'line_count' => (int) $row->line_count,
            ])->all())->all();
    }

    /**
     * 決済日基準の施術等／物販別 税抜・税額・税込（会計明細snapshotの合計。税抜を税込から再計算しない）。
     *
     * @return array<string, array<string, array{net:int,tax:int,gross:int}>>
     */
    private function salesSplitMetrics(string $startUtc, string $endUtc): array
    {
        $dateSql = 'DATE(DATE_ADD(paid.paid_at, INTERVAL 9 HOUR))';
        $categorySql = "CASE WHEN cl.item_type = 'product' THEN 'retail' ELSE 'treatment' END";
        $result = [];
        $rows = DB::table('checkouts as c')
            ->joinSub($this->paidCheckouts($startUtc, $endUtc), 'paid', 'paid.checkout_id', '=', 'c.id')
            ->join('checkout_lines as cl', 'cl.checkout_id', '=', 'c.id')
            ->where('c.status', CheckoutStatus::Finalized->value)
            ->groupByRaw("{$dateSql}, {$categorySql}")
            ->selectRaw("{$dateSql} AS business_date, {$categorySql} AS category, SUM(cl.net_amount) AS net, SUM(cl.tax_amount) AS tax, SUM(cl.gross_amount) AS gross")
            ->get();
        foreach ($rows as $row) {
            $result[(string) $row->business_date] ??= self::emptySalesSplit();
            $result[(string) $row->business_date][(string) $row->category] = [
                'net' => (int) $row->net, 'tax' => (int) $row->tax, 'gross' => (int) $row->gross,
            ];
        }

        return $result;
    }

    /**
     * 決済方法×配分先（施術等／物販）。配分の無い支払は unallocated として残し、推測で割り振らない。
     *
     * @return array<string, list<array{payment_method_id:int,code:string,name:string,amount:int,treatment_amount:int,retail_amount:int,unallocated_amount:int}>>
     */
    private function paymentCategoryMetrics(string $startUtc, string $endUtc): array
    {
        $dateSql = 'DATE(DATE_ADD(ct.received_at, INTERVAL 9 HOUR))';

        return DB::table('checkout_tenders as ct')->join('checkouts as c', 'c.id', '=', 'ct.checkout_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'ct.payment_method_id')
            ->leftJoin('checkout_tender_allocations as at', function ($join): void {
                $join->on('at.checkout_tender_id', '=', 'ct.id')->where('at.allocation_category', 'treatment');
            })
            ->leftJoin('checkout_tender_allocations as ar', function ($join): void {
                $join->on('ar.checkout_tender_id', '=', 'ct.id')->where('ar.allocation_category', 'retail');
            })
            // 配分未記録でも会計の明細が片方の区分だけなら配分先は一意（推測ではない）。混在時だけ未配分に残す。
            ->joinSub(DB::table('checkout_lines')->groupBy('checkout_id')->selectRaw(
                "checkout_id, COALESCE(SUM(CASE WHEN item_type = 'product' THEN gross_amount ELSE 0 END), 0) AS retail_gross, "
                ."COALESCE(SUM(CASE WHEN item_type = 'product' THEN 0 ELSE gross_amount END), 0) AS treatment_gross"
            ), 'comp', 'comp.checkout_id', '=', 'ct.checkout_id')
            ->where('c.status', CheckoutStatus::Finalized->value)->where('ct.status', CheckoutTenderStatus::Received->value)
            ->where('ct.received_at', '>=', $startUtc)->where('ct.received_at', '<', $endUtc)
            ->groupByRaw($dateSql)->groupBy('pm.id', 'pm.code', 'pm.name', 'pm.display_order')
            ->orderByRaw($dateSql)->orderBy('pm.display_order')->orderBy('pm.id')
            ->selectRaw("{$dateSql} AS business_date, pm.id AS payment_method_id, pm.code, pm.name, SUM(ct.amount) AS amount")
            ->selectRaw('COALESCE(SUM(CASE WHEN at.id IS NOT NULL THEN at.amount WHEN ar.id IS NULL AND comp.retail_gross = 0 THEN ct.amount ELSE 0 END), 0) AS treatment_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN ar.id IS NOT NULL THEN ar.amount WHEN at.id IS NULL AND comp.treatment_gross = 0 AND comp.retail_gross > 0 THEN ct.amount ELSE 0 END), 0) AS retail_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN at.id IS NULL AND ar.id IS NULL AND comp.retail_gross > 0 AND comp.treatment_gross > 0 THEN ct.amount ELSE 0 END), 0) AS unallocated_amount')
            ->get()->groupBy('business_date')->map(static fn ($rows): array => $rows->map(static fn (object $row): array => [
                'payment_method_id' => (int) $row->payment_method_id, 'code' => (string) $row->code, 'name' => (string) $row->name,
                'amount' => (int) $row->amount, 'treatment_amount' => (int) $row->treatment_amount,
                'retail_amount' => (int) $row->retail_amount, 'unallocated_amount' => (int) $row->unallocated_amount,
            ])->all())->all();
    }

    /** @return array<string, array{net:int,tax:int,gross:int}> */
    public static function emptySalesSplit(): array
    {
        return ['treatment' => ['net' => 0, 'tax' => 0, 'gross' => 0], 'retail' => ['net' => 0, 'tax' => 0, 'gross' => 0]];
    }

    /** @return array<string, int> */
    private function directTreatmentRevenue(string $startDate, string $endDate): array
    {
        return DB::table('visits as v')->join('visit_treatments as vt', function ($join): void {
            $join->on('vt.visit_id', '=', 'v.id')->where('vt.status', VisitTreatmentStatus::Completed->value);
        })->join('checkout_lines as cl', 'cl.visit_treatment_id', '=', 'vt.id')
            ->join('checkouts as c', function ($join): void {
                $join->on('c.id', '=', 'cl.checkout_id')->on('c.visit_id', '=', 'v.id')->where('c.status', CheckoutStatus::Finalized->value);
            })->where('v.status', VisitStatus::Completed->value)
            ->where('v.business_date', '>=', $startDate)->where('v.business_date', '<', $endDate)
            ->groupBy('v.business_date')->selectRaw('v.business_date, SUM(cl.gross_amount) AS amount')
            ->pluck('amount', 'business_date')->map(static fn (mixed $value): int => (int) $value)->all();
    }

    /** @return array<string, int> */
    private function allocatedTreatmentRevenue(string $startDate, string $endDate): array
    {
        // 元会計明細自体が施術直結ならdirect側で認識済みなので、事故データでも二重加算しない。
        return DB::table('revenue_allocations as ra')
            ->join('revenue_recognition_contracts as rrc', 'rrc.id', '=', 'ra.revenue_recognition_contract_id')
            ->leftJoin('checkout_lines as source_cl', 'source_cl.id', '=', 'rrc.source_checkout_line_id')
            ->whereNull('source_cl.visit_treatment_id')
            ->where('ra.recognized_on', '>=', $startDate)->where('ra.recognized_on', '<', $endDate)
            ->groupBy('ra.recognized_on')->selectRaw('ra.recognized_on, SUM(ra.amount) AS amount')
            ->pluck('amount', 'recognized_on')->map(static fn (mixed $value): int => (int) $value)->all();
    }

    private function completedVisits(string $startDate, string $endDate): Builder
    {
        return DB::table('visits as v')->where('v.business_date', '>=', $startDate)
            ->where('v.business_date', '<', $endDate)->where('v.status', VisitStatus::Completed->value);
    }

    /** @return array<string, mixed> */
    private function emptyFacts(): array
    {
        return [
            'visits' => [
                'visit_count' => 0, 'future_reservation_count' => 0, 'future_reservation_unknown_count' => 0,
                'first_visit_count' => 0, 'first_visit_reservation_count' => 0,
                'first_visit_reservation_unknown_count' => 0, 'accounting_pending_visit_count' => 0, 'visit_gross' => 0,
            ],
            'durations' => ['long_visit_count' => 0, 'unknown_treatment_minutes_visit_count' => 0],
            'categories' => [], 'unknown_category_visits' => 0, 'payment_methods' => [], 'taxes' => [],
            'sales_split' => self::emptySalesSplit(), 'payment_categories' => [],
            'direct_treatment_revenue' => 0, 'allocated_treatment_revenue' => 0,
        ];
    }
}
