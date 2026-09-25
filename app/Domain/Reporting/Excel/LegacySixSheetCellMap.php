<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use App\Domain\Reporting\MonthlyBusinessSummary;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/** 2026.10実原本の表示セルだけを対応付ける。業務計算はReportingのread modelが所有する。 */
final class LegacySixSheetCellMap
{
    public const SHEETS = ['数値', '日報', '月計表', '稼働率（社員）', '稼働率（アルバイト）', '年間計画書 (実数)'];

    /**
     * @param  array<int,array<string,mixed>>  $annualByYear
     * @param  array<string,mixed>  $staff
     * @return array<string,array<string,array{value:string|int|float|null,kind:string}>>
     */
    public function cells(MonthlyBusinessSummary $monthly, array $annualByYear, array $staff): array
    {
        $cells = array_fill_keys(self::SHEETS, []);
        $this->put($cells, '数値', 'A3', $monthly->year);
        $this->put($cells, '数値', 'B3', $monthly->month);
        $this->put($cells, '日報', 'B1', $monthly->year);
        $this->put($cells, '日報', 'C1', $monthly->month);
        $this->put($cells, '月計表', 'C1', $monthly->year);
        $this->put($cells, '月計表', 'D1', $monthly->month);

        // 原本の固定8万円/日と2種類の月目標は矛盾するため、ARKにない日目標は空欄にする。
        for ($day = 1; $day <= 31; $day++) {
            $this->put($cells, '数値', 'H'.($day + 3), null);
        }
        $this->put($cells, '数値', 'C6', $monthly->target);
        $this->put($cells, '数値', 'D6', $monthly->actualTotals['payment_date_revenue']);
        $this->put($cells, '数値', 'H35', $monthly->target);
        $this->put($cells, '数値', 'K35', $monthly->actualTotals['payment_date_revenue']);
        $this->put($cells, '数値', 'M35', $monthly->actualTotals['visit_count']);
        $this->put($cells, '数値', 'L35', $monthly->target === null ? null : $monthly->actualTotals['payment_date_revenue'] - $monthly->target);

        $methods = $monthly->actualTotals['payment_method_totals'];
        if (count($methods) > 7) {
            throw new RuntimeException('原本の支払方法欄は7列までです。帳票を欠落させずに出力するにはセル対応の更新が必要です。');
        }
        $taxes = $monthly->actualTotals['tax_totals'];
        if (count($taxes) > 5) {
            throw new RuntimeException('原本の税区分欄は5列までです。帳票を欠落させずに出力するにはセル対応の更新が必要です。');
        }
        foreach (range(3, 9) as $index => $column) {
            $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($column).'2', $methods[$index]['name'] ?? null, 'text');
        }
        foreach (range(11, 15) as $index => $column) {
            $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($column).'2', $taxes[$index]['tax_category_name'] ?? null, 'text');
        }
        $this->put($cells, '月計表', 'J2', '税込計', 'text');
        $this->put($cells, '月計表', 'P2', '税額計', 'text');
        $this->put($cells, '月計表', 'Q2', '税込売上', 'text');
        $this->put($cells, '月計表', 'AD2', '未分類', 'text');
        $this->put($cells, '月計表', 'C36', $monthly->target);
        $this->put($cells, '月計表', 'C37', $monthly->businessDays['input_days']);
        $this->put($cells, '月計表', 'C38', $monthly->businessDays['remaining']);
        $this->put($cells, '月計表', 'C39', $monthly->progress['required_daily_average']);
        $this->put($cells, '月計表', 'Q38', $monthly->averages['weekday_sales']);
        $this->put($cells, '月計表', 'Q39', $monthly->averages['weekend_sales']);

        foreach ($monthly->dailyRows as $day) {
            $date = $day['business_date'];
            $number = (int) substr($date, -2);
            $row = $number + 2;
            $this->put($cells, '月計表', 'A'.$row, $number);
            $this->put($cells, '月計表', 'B'.$row, $day['weekday'], 'text');
            $this->put($cells, '日報', 'B'.$row, $number);
            $this->put($cells, '日報', 'C'.$row, $day['weekday'], 'text');
            $numberRow = $number + 3;
            $this->put($cells, '数値', 'F'.$numberRow, $number);
            $this->put($cells, '数値', 'G'.$numberRow, $day['weekday'], 'text');

            foreach (range(3, 9) as $index => $column) {
                $amount = null;
                if (! $day['is_future'] && isset($methods[$index])) {
                    $amount = 0;
                    foreach ($day['payment_method_totals'] as $payment) {
                        if ($payment['payment_method_id'] === $methods[$index]['payment_method_id']) {
                            $amount = $payment['amount'];
                        }
                    }
                }
                $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($column).$row, $amount);
            }
            $dailyTax = $day['payment_date_revenue'] > 0 && $day['tax_totals'] === [] ? null : 0;
            foreach (range(11, 15) as $index => $column) {
                $amount = null;
                if (! $day['is_future'] && isset($taxes[$index])) {
                    $amount = 0;
                    foreach ($day['tax_totals'] as $tax) {
                        if ($tax['tax_category_code'] === $taxes[$index]['tax_category_code']
                            && $tax['tax_category_name'] === $taxes[$index]['tax_category_name']
                            && $tax['tax_rate_bps'] === $taxes[$index]['tax_rate_bps']) {
                            $amount += $tax['tax_amount'];
                        }
                    }
                    if ($dailyTax !== null) {
                        $dailyTax += $amount;
                    }
                }
                $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($column).$row, $amount);
            }
            $this->put($cells, '月計表', 'P'.$row, $day['is_future'] ? null : $dailyTax);
            $values = [
                'J' => 'payment_date_revenue', 'Q' => 'payment_date_revenue',
                'R' => 'visit_count', 'S' => 'long_visit_count', 'T' => 'future_reservation_count',
                'U' => 'future_reservation_rate', 'V' => 'first_visit_count',
                'W' => 'first_visit_reservation_count', 'X' => 'first_visit_reservation_rate',
            ];
            foreach ($values as $column => $key) {
                $value = $day['is_future'] ? null : match ($key) {
                    'future_reservation_rate' => $day['visit_count'] === 0 || $day['future_reservation_unknown_count'] > 0
                        ? null : $day['future_reservation_count'] / $day['visit_count'],
                    'first_visit_reservation_rate' => $day['first_visit_count'] === 0 || $day['first_visit_reservation_unknown_count'] > 0
                        ? null : $day['first_visit_reservation_count'] / $day['first_visit_count'],
                    'future_reservation_count' => $day['future_reservation_unknown_count'] > 0 ? null : $day[$key],
                    'first_visit_reservation_count' => $day['first_visit_reservation_unknown_count'] > 0 ? null : $day[$key],
                    default => $day[$key],
                };
                $this->put($cells, '月計表', $column.$row, $value, in_array($column, ['U', 'X'], true) ? 'rate' : 'number');
            }
            foreach (['M' => 'Y', 'T' => 'Z', 'A' => 'AA', 'M&T' => 'AB', 'A&T' => 'AC'] as $category => $column) {
                $this->put($cells, '月計表', $column.$row,
                    $day['is_future'] ? null : $day['analysis_category_visit_counts'][$category]);
            }
            $this->put($cells, '月計表', 'AD'.$row,
                $day['is_future'] ? null : $day['unknown_analysis_category_visit_count']);
            $this->put($cells, '数値', 'K'.$numberRow, $day['is_future'] ? null : $day['payment_date_revenue']);
            $this->put($cells, '数値', 'M'.$numberRow, $day['is_future'] ? null : $day['visit_count']);
        }
        // 28/29/30日月の余剰行も原本の10月値を残さない。
        for ($day = count($monthly->dailyRows) + 1; $day <= 31; $day++) {
            $row = $day + 2;
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD'] as $column) {
                $this->put($cells, '月計表', $column.$row, null);
            }
            $this->put($cells, '日報', 'B'.$row, null);
            $this->put($cells, '日報', 'C'.$row, null);
            $this->put($cells, '数値', 'F'.($day + 3), null);
            $this->put($cells, '数値', 'G'.($day + 3), null);
        }
        foreach ($methods as $index => $method) {
            $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($index + 3).'34', $method['amount']);
        }
        foreach ($taxes as $index => $tax) {
            $this->put($cells, '月計表', Coordinate::stringFromColumnIndex($index + 11).'34', $tax['tax_amount']);
        }
        $this->put($cells, '月計表', 'P34', $monthly->actualTotals['payment_date_revenue'] > 0 && $taxes === []
            ? null : array_sum(array_column($taxes, 'tax_amount')));
        foreach (['J' => 'payment_date_revenue', 'Q' => 'payment_date_revenue', 'R' => 'visit_count',
            'S' => 'long_visit_count', 'T' => 'future_reservation_count', 'V' => 'first_visit_count',
            'W' => 'first_visit_reservation_count'] as $column => $key) {
            $value = match ($key) {
                'future_reservation_count' => $monthly->actualTotals['future_reservation_unknown_count'] > 0
                    ? null : $monthly->actualTotals[$key],
                'first_visit_reservation_count' => $monthly->actualTotals['first_visit_reservation_unknown_count'] > 0
                    ? null : $monthly->actualTotals[$key],
                default => $monthly->actualTotals[$key],
            };
            $this->put($cells, '月計表', $column.'34', $value);
        }
        $this->put($cells, '月計表', 'U34', $monthly->actualRatios['future_reservation_rate']->value, 'rate');
        $this->put($cells, '月計表', 'X34', $monthly->actualRatios['first_visit_reservation_rate']->value, 'rate');
        foreach (['M' => 'Y', 'T' => 'Z', 'A' => 'AA', 'M&T' => 'AB', 'A&T' => 'AC'] as $category => $column) {
            $this->put($cells, '月計表', $column.'34', $monthly->actualTotals['analysis_category_visit_counts'][$category]);
        }
        $this->put($cells, '月計表', 'AD34', $monthly->actualTotals['unknown_analysis_category_visit_count']);

        $this->staffSheets($cells, $staff, $monthly->year, $monthly->month);
        $this->annualSheet($cells, $monthly, $annualByYear);

        return $cells;
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells @param array<string,mixed> $staff */
    private function staffSheets(array &$cells, array $staff, int $year, int $month): void
    {
        $daysInMonth = CarbonImmutable::create($year, $month, 1)->daysInMonth;
        foreach (['employee' => '稼働率（社員）', 'part_time' => '稼働率（アルバイト）'] as $employment => $sheet) {
            $this->put($cells, $sheet, 'B3', $month);
            $ids = [];
            foreach ($staff['monthly_rows'] as $total) {
                if ($total['employment_type_code'] === $employment && ! in_array($total['staff_id'], $ids, true)) {
                    $ids[] = $total['staff_id'];
                }
            }
            if (count($ids) > 4) {
                throw new RuntimeException($sheet.'の原本はスタッフ4名までです。欠落を避けるため出力を中止しました。');
            }
            for ($slot = 0; $slot < 4; $slot++) {
                $base = 3 + $slot * 8;
                $nameCell = Coordinate::stringFromColumnIndex($base).'3';
                $staffId = $ids[$slot] ?? null;
                $name = null;
                foreach ($staff['staff'] as $member) {
                    if ($member['id'] === $staffId) {
                        $name = $member['name'];
                    }
                }
                $this->put($cells, $sheet, $nameCell, $name, 'text');
                for ($day = 1; $day <= 31; $day++) {
                    $row = $day + 4;
                    $entry = null;
                    foreach ($staff['daily_rows'] as $candidate) {
                        if ($candidate['staff_id'] === $staffId && $candidate['employment_type_code'] === $employment
                            && (int) substr($candidate['business_date'], -2) === $day) {
                            $entry = $candidate;
                            break;
                        }
                    }
                    $this->staffRow($cells, $sheet, $base, $row, $entry);
                }
                $total = null;
                foreach ($staff['monthly_rows'] as $candidate) {
                    if ($candidate['staff_id'] === $staffId && $candidate['employment_type_code'] === $employment) {
                        $total = $candidate;
                    }
                }
                $this->staffRow($cells, $sheet, $base, 36, $total);
            }
            for ($day = 1; $day <= 31; $day++) {
                $row = $day + 4;
                $this->put($cells, $sheet, 'B'.$row, $day <= $daysInMonth ? $day : null);
                $dailyEntries = array_values(array_filter($staff['daily_rows'], static fn (array $entry): bool => $entry['employment_type_code'] === $employment
                    && (int) substr($entry['business_date'], -2) === $day));
                $this->staffRow($cells, $sheet, 35, $row, $this->aggregateStaff($dailyEntries));
            }
            $monthEntries = array_values(array_filter($staff['monthly_rows'], static fn (array $entry): bool => $entry['employment_type_code'] === $employment));
            $this->staffRow($cells, $sheet, 35, 36, $this->aggregateStaff($monthEntries));
        }
    }

    /** @param list<array<string,mixed>> $entries @return array<string,int|float|null>|null */
    private function aggregateStaff(array $entries): ?array
    {
        if ($entries === []) {
            return null;
        }
        $result = [];
        foreach (['occupied_minutes', 'patient_count', 'working_minutes', 'future_reservation_count', 'nomination_count'] as $key) {
            $result[$key] = 0;
            foreach ($entries as $entry) {
                if ($entry[$key] === null) {
                    $result[$key] = null;
                    break;
                }
                $result[$key] += $entry[$key];
            }
        }
        $patients = $result['patient_count'];
        $working = $result['working_minutes'];
        $result['reservation_rate'] = $patients === 0 || $result['future_reservation_count'] === null
            ? null : $result['future_reservation_count'] / $patients;
        $result['nomination_rate'] = $patients === 0 || $result['nomination_count'] === null
            ? null : $result['nomination_count'] / $patients;
        $result['legacy_utilization_rate'] = $working === null || $working === 0 || $result['occupied_minutes'] === null
            ? null : $result['occupied_minutes'] / $working;

        return $result;
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells @param array<string,mixed>|null $entry */
    private function staffRow(array &$cells, string $sheet, int $base, int $row, ?array $entry): void
    {
        foreach (['occupied_minutes', 'patient_count', 'working_minutes', 'future_reservation_count',
            'nomination_count', 'reservation_rate', 'nomination_rate', 'legacy_utilization_rate'] as $offset => $key) {
            $coordinate = Coordinate::stringFromColumnIndex($base + $offset).$row;
            $this->put($cells, $sheet, $coordinate, $entry[$key] ?? null, $offset >= 5 ? 'rate' : 'number');
        }
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells @param array<int,array<string,mixed>> $annualByYear */
    private function annualSheet(array &$cells, MonthlyBusinessSummary $monthly, array $annualByYear): void
    {
        $sheet = '年間計画書 (実数)';
        $fiscalStart = $monthly->month >= 4 ? $monthly->year : $monthly->year - 1;
        $this->put($cells, $sheet, 'A1', sprintf('ARK自由が丘店 %d年度計画・実績', $fiscalStart), 'text');
        $this->put($cells, $sheet, 'A5', '実績売上', 'text');
        $this->put($cells, $sheet, 'A6', '来店数', 'text');
        $targetTotal = 0;
        $actualTotal = 0;
        $visitTotal = 0;
        $targetUnknown = false;
        foreach (range(0, 11) as $index) {
            $fiscalMonth = (($index + 3) % 12) + 1;
            $calendarYear = $index < 9 ? $fiscalStart : $fiscalStart + 1;
            $entry = $annualByYear[$calendarYear]['months'][$fiscalMonth - 1];
            $column = Coordinate::stringFromColumnIndex(3 + $index * 2);
            $ratioColumn = Coordinate::stringFromColumnIndex(4 + $index * 2);
            $target = $entry['target_amount'];
            $actual = $entry['is_future'] ? null : $entry['actual_totals']['payment_date_revenue'];
            $visits = $entry['is_future'] ? null : $entry['actual_totals']['visit_count'];
            $this->put($cells, $sheet, $column.'4', $target);
            $this->put($cells, $sheet, $column.'5', $actual);
            $this->put($cells, $sheet, $column.'6', $visits);
            $this->put($cells, $sheet, $ratioColumn.'5', $target === null || $target === 0 || $actual === null ? null : $actual / $target, 'rate');
            if ($target === null) {
                $targetUnknown = true;
            } else {
                $targetTotal += $target;
            }
            $actualTotal += $actual ?? 0;
            $visitTotal += $visits ?? 0;
        }
        $this->put($cells, $sheet, 'AA4', $targetUnknown ? null : $targetTotal);
        $this->put($cells, $sheet, 'AA5', $actualTotal);
        $this->put($cells, $sheet, 'AA6', $visitTotal);
        $this->put($cells, $sheet, 'AB5', $targetUnknown || $targetTotal === 0 ? null : $actualTotal / $targetTotal, 'rate');
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells */
    private function put(array &$cells, string $sheet, string $coordinate, string|int|float|null $value, string $kind = 'number'): void
    {
        $cells[$sheet][$coordinate] = ['value' => $value, 'kind' => $kind];
    }
}
