<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use App\Domain\Reporting\MonthlyBusinessSummary;
use App\Models\DailyBusinessNote;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/** 2026年10月原本のセル位置が正本。見出し・原本予算・非対象数式は書き換えない。 */
final class LegacySixSheetCellMap
{
    public const SHEETS = ['数値', '日報', '月計表', '稼働率（社員）', '稼働率（アルバイト）', '年間計画書 (実数)'];

    /** 原本「月計表」2行目の固定決済列。新規マスタで列を増やさない。 */
    public const PAYMENT_COLUMNS = [
        'cash' => 'C', 'paypay' => 'D', 'airpay' => 'E', 'square' => 'F',
        'smart_payment' => 'G', 'gift_certificate' => 'H', 'id' => 'I',
    ];

    /** 原本「月計表」K〜Nの物販（8%）決済列。O「予備」は用途不明のため書かない。 */
    public const RETAIL_PAYMENT_COLUMNS = ['cash' => 'K', 'paypay' => 'L', 'airpay' => 'M', 'id' => 'N'];

    private const CATEGORY_COLUMNS = ['M' => 'Y', 'T' => 'Z', 'A' => 'AA', 'M&T' => 'AB', 'A&T' => 'AC'];

    /**
     * @param  array<string,mixed>  $staff
     * @param  array<string,DailyBusinessNote>  $notes
     * @return array<string,array<string,array{value:string|int|float|null,kind:string}>>
     */
    public function cells(MonthlyBusinessSummary $monthly, array $staff, array $notes): array
    {
        $cells = array_fill_keys(self::SHEETS, []);
        $this->put($cells, '数値', 'A3', $monthly->year);
        $this->put($cells, '数値', 'B3', $monthly->month);
        $this->put($cells, '日報', 'B1', $monthly->year);
        $this->put($cells, '日報', 'C1', $monthly->month);
        $this->put($cells, '月計表', 'C1', $monthly->year);
        $this->put($cells, '月計表', 'D1', $monthly->month);

        // 数値シートの予算・経費・日目標と年間計画書の計画値は原本所有。
        // ARKに対応する日別売上・来店だけを直接書き、残りの原本数式は保持する。
        $this->put($cells, '月計表', 'C36', $monthly->target);
        $this->put($cells, '月計表', 'C37', $monthly->businessDays['input_days']);
        $this->put($cells, '月計表', 'C38', $monthly->businessDays['remaining']);
        $this->put($cells, '月計表', 'C39', $monthly->progress['required_daily_average']);

        for ($day = 1; $day <= 31; $day++) {
            $row = $day + 2;
            $numberRow = $day + 3;
            $daily = $monthly->dailyRows[$day - 1] ?? null;
            $exists = $daily !== null;
            $actual = $exists && ! $daily['is_future'];
            $date = $daily['business_date'] ?? null;
            $weekday = $daily['weekday'] ?? null;

            $this->put($cells, '月計表', 'A'.$row, $exists ? $day : null);
            $this->put($cells, '月計表', 'B'.$row, $weekday, 'text');
            $this->put($cells, '日報', 'B'.$row, $exists ? $day : null);
            $this->put($cells, '日報', 'C'.$row, $weekday, 'text');
            $this->put($cells, '数値', 'F'.$numberRow, $exists ? $day : null);
            $this->put($cells, '数値', 'G'.$numberRow, $weekday, 'text');

            $note = $date === null ? null : ($notes[$date] ?? null);
            $this->put($cells, '日報', 'D'.$row, $actual ? $note?->business_condition : null, 'text');
            $this->put($cells, '日報', 'H'.$row, $actual ? $note?->reflection : null, 'text');

            // C〜I=施術等の決済別、K〜N=物販の決済別（支払配分）。J/P/Qは会計明細snapshotの税抜で、
            // 旧原本の固定税率式（/1.1・/1.08、P列のN・O漏れ）は再現しない（Task 11-20）。
            $treatmentPayments = array_column($daily['payment_category_totals'] ?? [], 'treatment_amount', 'code');
            $retailPayments = array_column($daily['payment_category_totals'] ?? [], 'retail_amount', 'code');
            foreach (self::PAYMENT_COLUMNS as $code => $column) {
                $this->put($cells, '月計表', $column.$row, $actual ? ($treatmentPayments[$code] ?? 0) : null);
            }
            foreach (self::RETAIL_PAYMENT_COLUMNS as $code => $column) {
                $this->put($cells, '月計表', $column.$row, $actual ? ($retailPayments[$code] ?? 0) : null);
            }
            $split = $daily['sales_split'] ?? null;
            $this->put($cells, '月計表', 'J'.$row, $actual ? $split['treatment']['net'] : null);
            $this->put($cells, '月計表', 'P'.$row, $actual ? $split['retail']['net'] : null);
            $this->put($cells, '月計表', 'Q'.$row, $actual ? $daily['net_sales'] : null);
            foreach (['R' => 'visit_count', 'S' => 'long_visit_count', 'T' => 'future_reservation_count',
                'V' => 'first_visit_count', 'W' => 'first_visit_reservation_count'] as $column => $key) {
                $unknown = $actual && (($key === 'future_reservation_count' && $daily['future_reservation_unknown_count'] > 0)
                    || ($key === 'first_visit_reservation_count' && $daily['first_visit_reservation_unknown_count'] > 0));
                $this->put($cells, '月計表', $column.$row, $actual && ! $unknown ? $daily[$key] : null);
            }
            $this->put($cells, '月計表', 'U'.$row, $actual ? $daily['future_reservation_rate']->value : null, 'rate');
            $this->put($cells, '月計表', 'X'.$row, $actual ? $daily['first_visit_reservation_rate']->value : null, 'rate');
            foreach (self::CATEGORY_COLUMNS as $category => $column) {
                $this->put($cells, '月計表', $column.$row,
                    $actual ? ($daily['analysis_category_visit_counts'][$category] ?? 0) : null);
            }
            // 原本の数値!Kは月計表!Q（売上金＝税抜）を参照していたため、ARKの税抜売上を書く。
            $this->put($cells, '数値', 'K'.$numberRow, $actual ? $daily['net_sales'] : null);
            $this->put($cells, '数値', 'M'.$numberRow, $actual ? $daily['visit_count'] : null);
        }

        $totals = $monthly->actualTotals;
        $treatmentPayments = array_column($totals['payment_category_totals'], 'treatment_amount', 'code');
        $retailPayments = array_column($totals['payment_category_totals'], 'retail_amount', 'code');
        foreach (self::PAYMENT_COLUMNS as $code => $column) {
            $this->put($cells, '月計表', $column.'34', $treatmentPayments[$code] ?? 0);
        }
        foreach (self::RETAIL_PAYMENT_COLUMNS as $code => $column) {
            $this->put($cells, '月計表', $column.'34', $retailPayments[$code] ?? 0);
        }
        $this->put($cells, '月計表', 'J34', $totals['sales_split']['treatment']['net']);
        $this->put($cells, '月計表', 'P34', $totals['sales_split']['retail']['net']);
        $this->put($cells, '月計表', 'Q34', $totals['net_sales']);
        foreach (['R' => 'visit_count', 'S' => 'long_visit_count', 'T' => 'future_reservation_count',
            'V' => 'first_visit_count', 'W' => 'first_visit_reservation_count'] as $column => $key) {
            $unknown = ($key === 'future_reservation_count' && $totals['future_reservation_unknown_count'] > 0)
                || ($key === 'first_visit_reservation_count' && $totals['first_visit_reservation_unknown_count'] > 0);
            $this->put($cells, '月計表', $column.'34', $unknown ? null : $totals[$key]);
        }
        $this->put($cells, '月計表', 'U34', $monthly->actualRatios['future_reservation_rate']->value, 'rate');
        $this->put($cells, '月計表', 'X34', $monthly->actualRatios['first_visit_reservation_rate']->value, 'rate');
        foreach (self::CATEGORY_COLUMNS as $category => $column) {
            $this->put($cells, '月計表', $column.'34', $totals['analysis_category_visit_counts'][$category] ?? 0);
        }

        $this->staffSheets($cells, $staff, $monthly->year, $monthly->month, $monthly->asOfDate);

        return $cells;
    }

    /** @param array<string,mixed> $staff @return list<string> */
    public function warnings(MonthlyBusinessSummary $monthly, array $staff): array
    {
        $warnings = [];
        foreach ($monthly->actualTotals['payment_category_totals'] as $method) {
            if (! isset(self::PAYMENT_COLUMNS[$method['code']]) && $method['treatment_amount'] !== 0) {
                $warnings[] = 'unmapped payment method: '.$method['code'].' ('.$method['treatment_amount'].'円)';
            }
            if (! isset(self::RETAIL_PAYMENT_COLUMNS[$method['code']]) && $method['retail_amount'] !== 0) {
                $warnings[] = 'unmapped retail payment method: '.$method['code'].' ('.$method['retail_amount'].'円)';
            }
            if ($method['unallocated_amount'] !== 0) {
                $warnings[] = 'unallocated tender: '.$method['code'].' ('.$method['unallocated_amount'].'円)';
            }
        }
        if ($monthly->actualTotals['unknown_analysis_category_visit_count'] > 0) {
            $warnings[] = 'unmapped analysis category: '.$monthly->actualTotals['unknown_analysis_category_visit_count'].' visits';
        }
        foreach ($staff['monthly_rows'] as $entry) {
            if (! in_array($entry['employment_type_code'], ['employee', 'part_time'], true)) {
                $warnings[] = 'unmapped employment type: '.($entry['employment_type_code'] ?? 'unknown');
            }
        }

        return array_values(array_unique($warnings));
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells @param array<string,mixed> $staff */
    private function staffSheets(array &$cells, array $staff, int $year, int $month, string $asOfDate): void
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
                $staffId = $ids[$slot] ?? null;
                $name = null;
                foreach ($staff['staff'] as $member) {
                    if ($member['id'] === $staffId) {
                        $name = $member['name'];
                    }
                }
                $this->put($cells, $sheet, Coordinate::stringFromColumnIndex($base).'3', $name, 'text');
                for ($day = 1; $day <= 31; $day++) {
                    $entry = null;
                    if ($staffId !== null && $day <= $daysInMonth && sprintf('%04d-%02d-%02d', $year, $month, $day) <= $asOfDate) {
                        foreach ($staff['daily_rows'] as $candidate) {
                            if ($candidate['staff_id'] === $staffId && $candidate['employment_type_code'] === $employment
                                && (int) substr($candidate['business_date'], -2) === $day) {
                                $entry = $candidate;
                                break;
                            }
                        }
                    }
                    $this->staffRow($cells, $sheet, $base, $day + 4, $entry);
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
                $date = $day <= $daysInMonth ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
                $this->put($cells, $sheet, 'B'.($day + 4), $date === null ? null : $day);
                $dailyEntries = $date === null || $date > $asOfDate ? [] : array_values(array_filter($staff['daily_rows'],
                    static fn (array $entry): bool => $entry['employment_type_code'] === $employment
                        && $entry['business_date'] === $date));
                $this->staffRow($cells, $sheet, 35, $day + 4, $this->aggregateStaff($dailyEntries));
            }
            $monthEntries = array_values(array_filter($staff['monthly_rows'],
                static fn (array $entry): bool => $entry['employment_type_code'] === $employment));
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
            $this->put($cells, $sheet, Coordinate::stringFromColumnIndex($base + $offset).$row,
                $entry[$key] ?? null, $offset >= 5 ? 'rate' : 'number');
        }
    }

    /** @param array<string,array<string,array{value:string|int|float|null,kind:string}>> $cells */
    private function put(array &$cells, string $sheet, string $coordinate, string|int|float|null $value, string $kind = 'number'): void
    {
        $cells[$sheet][$coordinate] = ['value' => $value, 'kind' => $kind];
    }
}
