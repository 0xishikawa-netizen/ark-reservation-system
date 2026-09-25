<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use App\Domain\Reporting\MonthlyBusinessSummary;
use App\Domain\Reporting\MonthlyCustomerSummary;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** 原本未提供時の版管理された暫定cell map。Reporting値以外の業務計算はしない。 */
final class ArkSixSheetCellMap
{
    public const SHEETS = ['数値', '日報', '月計表', '稼働率（社員）', '稼働率（アルバイト）', '年間計画書（実数）'];

    /**
     * @param  array<string,mixed>  $annual
     * @param  array<string,mixed>  $staff
     * @return array<string,array<string,array{value:string|int|float|null,kind:string}>>
     */
    public function cells(MonthlyBusinessSummary $monthly, MonthlyCustomerSummary $customers, array $annual, array $staff): array
    {
        $cells = array_fill_keys(self::SHEETS, []);
        $period = $monthly->monthKey;
        $asOf = $monthly->asOfDate;
        foreach (self::SHEETS as $sheet) {
            $this->put($cells, $sheet, 'A1', $sheet);
            $this->put($cells, $sheet, 'A2', '対象月');
            $this->put($cells, $sheet, 'B2', $period);
            $this->put($cells, $sheet, 'A3', '基準日');
            $this->put($cells, $sheet, 'B3', $asOf);
        }
        $this->put($cells, '年間計画書（実数）', 'A2', '対象年');
        $this->put($cells, '年間計画書（実数）', 'B2', (string) $annual['year']);
        $this->put($cells, '年間計画書（実数）', 'B3', $annual['as_of_date']);

        $metrics = [
            ['決済日売上', $monthly->actualTotals['payment_date_revenue'], 'number'],
            ['施術日売上', $monthly->actualTotals['treatment_date_revenue'], 'number'],
            ['売上目標', $monthly->target, 'number'],
            ['目標達成率', $monthly->progress['achievement_rate'], 'rate'],
            ['来店数', $monthly->actualTotals['visit_count'], 'number'],
            ['ロング', $monthly->actualTotals['long_visit_count'], 'number'],
            ['次回予約人数', $monthly->actualTotals['future_reservation_count'], 'number'],
            ['次回予約率', $monthly->actualRatios['future_reservation_rate']->value, 'rate'],
            ['初診', $monthly->actualTotals['first_visit_count'], 'number'],
            ['初診予約', $monthly->actualTotals['first_visit_reservation_count'], 'number'],
            ['新規', $customers->newCustomers, 'number'],
            ['再診', $customers->returningCustomers, 'number'],
            ['離反', $customers->churnCustomers, 'number'],
            ['2回目到達', $customers->reach['2']['numerator'], 'number'],
            ['6回目到達', $customers->reach['6']['numerator'], 'number'],
            ['10回目到達', $customers->reach['10']['numerator'], 'number'],
        ];
        $this->headers($cells, '数値', 5, ['指標', '値']);
        foreach ($metrics as $index => [$label, $value, $kind]) {
            $row = $index + 6;
            $this->put($cells, '数値', 'A'.$row, $label);
            $this->put($cells, '数値', 'B'.$row, $value, $kind);
        }
        $this->headersAt($cells, '数値', 5, 4, ['支払方法', '金額']);
        foreach ($monthly->actualTotals['payment_method_totals'] as $index => $method) {
            $this->put($cells, '数値', 'D'.($index + 6), $method['name']);
            $this->put($cells, '数値', 'E'.($index + 6), $method['amount'], 'number');
        }
        $this->headersAt($cells, '数値', 5, 7, ['税区分', '税抜', '税額', '税込']);
        foreach ($monthly->actualTotals['tax_totals'] as $index => $tax) {
            $row = $index + 6;
            $this->put($cells, '数値', 'G'.$row, $tax['tax_category_name'] ?? $tax['tax_category_code'] ?? '不明');
            $this->put($cells, '数値', 'H'.$row, $tax['net_amount'], 'number');
            $this->put($cells, '数値', 'I'.$row, $tax['tax_amount'], 'number');
            $this->put($cells, '数値', 'J'.$row, $tax['gross_amount'], 'number');
        }

        $dailyFields = [
            ['日付', 'business_date'], ['曜日', 'weekday'], ['来店', 'visit_count'], ['ロング', 'long_visit_count'],
            ['予約人数', 'future_reservation_count'], ['初診', 'first_visit_count'],
            ['初診予約', 'first_visit_reservation_count'], ['決済日売上', 'payment_date_revenue'],
            ['施術日売上', 'treatment_date_revenue'],
        ];
        $this->headers($cells, '日報', 4, array_column($dailyFields, 0));
        foreach ($monthly->dailyRows as $index => $day) {
            foreach ($dailyFields as $column => [, $key]) {
                $value = $day['is_future'] && $column >= 2 ? null : $day[$key];
                $this->put($cells, '日報', Coordinate::stringFromColumnIndex($column + 1).($index + 5), $value,
                    $column < 2 ? 'text' : 'number');
            }
        }

        $monthFields = [
            ['日付', 'business_date'], ['曜日', 'weekday'], ['決済日売上', 'payment_date_revenue'],
            ['施術日売上', 'treatment_date_revenue'], ['来店', 'visit_count'], ['ロング', 'long_visit_count'],
            ['予約人数', 'future_reservation_count'], ['初診', 'first_visit_count'], ['初診予約', 'first_visit_reservation_count'],
        ];
        $this->headers($cells, '月計表', 4, array_column($monthFields, 0));
        foreach ($monthly->dailyRows as $index => $day) {
            foreach ($monthFields as $column => [, $key]) {
                $value = $day['is_future'] && $column >= 2 ? null : $day[$key];
                $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($column + 1).($index + 5), $value,
                    $column < 2 ? 'text' : 'number');
            }
        }
        $this->put($cells, '月計表', 'A37', '月合計');
        foreach (array_slice($monthFields, 2) as $column => [, $key]) {
            $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($column + 3).'37', $monthly->actualTotals[$key], 'number');
        }
        $this->put($cells, '月計表', 'A38', '売上目標');
        $this->put($cells, '月計表', 'C38', $monthly->target, 'number');
        $this->put($cells, '月計表', 'A39', '目標達成率');
        $this->put($cells, '月計表', 'C39', $monthly->progress['achievement_rate'], 'rate');

        $staffFields = [
            ['日付', 'business_date', 'text'], ['スタッフ', 'staff_name', 'text'],
            ['稼働分', 'occupied_minutes', 'number'], ['勤務分', 'working_minutes', 'number'],
            ['既存互換稼働率', 'legacy_utilization_rate', 'rate'], ['予約可能分', 'bookable_minutes', 'number'],
            ['予約可能基準稼働率', 'bookable_utilization_rate', 'rate'],
        ];
        foreach (['employee' => '稼働率（社員）', 'part_time' => '稼働率（アルバイト）'] as $code => $sheet) {
            $this->headers($cells, $sheet, 4, array_column($staffFields, 0));
            $row = 5;
            foreach ($staff['daily_rows'] as $entry) {
                if ($entry['employment_type_code'] !== $code) {
                    continue;
                }
                foreach ($staffFields as $column => [, $key, $kind]) {
                    $this->put($cells, $sheet, Coordinate::stringFromColumnIndex($column + 1).$row, $entry[$key], $kind);
                }
                $row++;
            }
        }

        $annualFields = [
            ['月', 'month', 'number'], ['決済日売上', 'payment_date_revenue', 'number'],
            ['施術日売上', 'treatment_date_revenue', 'number'], ['目標', 'target_amount', 'number'],
            ['達成率', 'achievement_rate', 'rate'], ['来店', 'visit_count', 'number'],
            ['ロング', 'long_visit_count', 'number'], ['予約人数', 'future_reservation_count', 'number'],
            ['初診', 'first_visit_count', 'number'], ['初診予約', 'first_visit_reservation_count', 'number'],
            ['新規', 'new_customers', 'number'], ['再診', 'returning_customers', 'number'],
            ['離反', 'churn_customers', 'number'], ['2回目到達', 'reached_2', 'number'],
            ['6回目到達', 'reached_6', 'number'], ['10回目到達', 'reached_10', 'number'],
        ];
        $this->headers($cells, '年間計画書（実数）', 4, array_column($annualFields, 0));
        foreach ($annual['months'] as $index => $month) {
            foreach ($annualFields as $column => [, $key, $kind]) {
                $value = $month['is_future'] && $key !== 'month' && $key !== 'target_amount' ? null
                    : ($month['actual_totals'][$key] ?? $month[$key]);
                $this->put($cells, '年間計画書（実数）', Coordinate::stringFromColumnIndex($column + 1).($index + 5), $value, $kind);
            }
        }
        $this->put($cells, '年間計画書（実数）', 'A17', '年間合計');
        foreach (array_slice($annualFields, 1) as $column => [, $key, $kind]) {
            $this->put($cells, '年間計画書（実数）', Coordinate::stringFromColumnIndex($column + 2).'17',
                $annual['as_of_totals'][$key] ?? $annual['totals'][$key], $kind);
        }

        return $cells;
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells */
    private function put(array &$cells, string $sheet, string $cell, string|int|float|null $value, string $kind = 'text'): void
    {
        $cells[$sheet][$cell] = ['value' => $value, 'kind' => $kind];
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells @param list<string> $labels */
    private function headers(array &$cells, string $sheet, int $row, array $labels): void
    {
        $this->headersAt($cells, $sheet, $row, 1, $labels);
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells @param list<string> $labels */
    private function headersAt(array &$cells, string $sheet, int $row, int $firstColumn, array $labels): void
    {
        foreach ($labels as $index => $label) {
            $this->put($cells, $sheet, Coordinate::stringFromColumnIndex($firstColumn + $index).$row, $label);
        }
    }
}
