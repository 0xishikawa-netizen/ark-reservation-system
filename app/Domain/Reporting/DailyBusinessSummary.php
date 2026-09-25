<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use JsonSerializable;
use LogicException;

/**
 * 月計・年間集計・帳票が共通利用する日次read model。
 *
 * @phpstan-type PaymentMethodTotal array{payment_method_id: int, code: string, name: string, amount: int}
 * @phpstan-type TaxTotal array{tax_category_code: string|null, tax_category_name: string|null, tax_rate_bps: int|null, net_amount: int, tax_amount: int, gross_amount: int, line_count: int}
 */
final readonly class DailyBusinessSummary implements JsonSerializable
{
    /**
     * @param  list<array{payment_method_id: int, code: string, name: string, amount: int}>  $paymentMethodTotals
     * @param  list<array{tax_category_code: string|null, tax_category_name: string|null, tax_rate_bps: int|null, net_amount: int, tax_amount: int, gross_amount: int, line_count: int}>  $taxTotals
     * @param  array<string, int>  $analysisCategoryVisitCounts
     */
    public function __construct(
        public string $businessDate,
        public int $weekdayIso,
        public string $weekday,
        public int $paymentDateRevenue,
        public int $treatmentDateRevenue,
        public int $directTreatmentRevenue,
        public int $allocatedTreatmentRevenue,
        public array $paymentMethodTotals,
        public array $taxTotals,
        public int $visitCount,
        public int $longVisitCount,
        public int $unknownTreatmentMinutesVisitCount,
        public int $futureReservationCount,
        public int $futureReservationUnknownCount,
        public DailyRatio $futureReservationRate,
        public int $firstVisitCount,
        public int $firstVisitReservationCount,
        public int $firstVisitReservationUnknownCount,
        public DailyRatio $firstVisitReservationRate,
        public array $analysisCategoryVisitCounts,
        public int $unknownAnalysisCategoryVisitCount,
        public int $accountingPendingVisitCount,
    ) {
        if ($futureReservationCount > $visitCount
            || $firstVisitCount > $visitCount
            || $firstVisitReservationCount > $firstVisitCount
            || $longVisitCount > $visitCount) {
            throw new LogicException('日次集計の件数不変条件に違反しています。');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'business_date' => $this->businessDate,
            'weekday_iso' => $this->weekdayIso,
            'weekday' => $this->weekday,
            'payment_date_revenue' => $this->paymentDateRevenue,
            'treatment_date_revenue' => $this->treatmentDateRevenue,
            'direct_treatment_revenue' => $this->directTreatmentRevenue,
            'allocated_treatment_revenue' => $this->allocatedTreatmentRevenue,
            'payment_method_totals' => $this->paymentMethodTotals,
            'tax_totals' => $this->taxTotals,
            'visit_count' => $this->visitCount,
            'long_visit_count' => $this->longVisitCount,
            'unknown_treatment_minutes_visit_count' => $this->unknownTreatmentMinutesVisitCount,
            'future_reservation_count' => $this->futureReservationCount,
            'future_reservation_unknown_count' => $this->futureReservationUnknownCount,
            'future_reservation_rate' => $this->futureReservationRate,
            'first_visit_count' => $this->firstVisitCount,
            'first_visit_reservation_count' => $this->firstVisitReservationCount,
            'first_visit_reservation_unknown_count' => $this->firstVisitReservationUnknownCount,
            'first_visit_reservation_rate' => $this->firstVisitReservationRate,
            'analysis_category_visit_counts' => $this->analysisCategoryVisitCounts,
            'unknown_analysis_category_visit_count' => $this->unknownAnalysisCategoryVisitCount,
            'accounting_pending_visit_count' => $this->accountingPendingVisitCount,
        ];
    }
}
