<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Import;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 旧Excel / Google Sheets書出しを、Task 11-12の中間形式（historical_aggregate / *_detail 行）へ変換する（Task 11-26）。
 *
 * - 原本は読み取り専用で開き、保存しない。式は実行せず、入力セル（人が入力した値）だけを使って ARK 側で再集計する。
 *   旧式の結果（率・合計・COUNTIF等）は取り込まず、比較用 `legacy_reported` としてだけ返す（旧式の不具合を再現しない）。
 * - 顧客・来店・勤怠などの明細は `*_detail` として手動確認へ回し、業務事実（Visit / Checkout / Customer）を自動生成しない。
 * - 氏名・フリガナ・番地は出力しない。住所は都道府県・市区町村だけ。旧コース名は明示mappingが無ければ手動確認。
 */
final class LegacyWorkbookConverter
{
    public const KINDS = ['customer_list', 'staff_utilization', 'time_band', 'attendance', 'daily_ledger', 'monthly_sheet'];

    public const HEADER = ['record_type', 'metric_code', 'dimension', 'period_start', 'period_end', 'value', 'source_identifier', 'detail'];

    /** 旧資料の来店動機表記 → ARK来店動機マスタcode。表記違いだけを統一し、意味の違う値は統合しない。 */
    public const CHANNEL_ALIASES = [
        'ホットペッパー' => 'hotpepper', 'ホトぺ' => 'hotpepper', 'Hotpepper' => 'hotpepper', 'ホットペッパービューティー' => 'hotpepper',
        'EPARK' => 'epark', '紹介' => 'referral', 'チラシ' => 'flyer', 'HP' => 'website', 'OZmall' => 'ozmall',
        '都立' => 'toritsu', '看板' => 'signboard', 'その他' => 'other',
    ];

    /** 旧資料の来店目的のうち、ARKマスタと完全一致するもの。それ以外は手動確認。 */
    public const PURPOSE_EXACT = [
        '痛みを取りたい' => 'pain_relief', '根本的に治したい' => 'root_cause', 'リラクゼーション' => 'relaxation',
        '運動不足解消' => 'exercise', 'その他' => 'other',
    ];

    /** 旧日計表・月計表の支払方法表記 → ARK決済手段code。 */
    public const PAYMENT_ALIASES = [
        '現金' => 'cash', 'PayPay' => 'paypay', 'AirPAY' => 'airpay', 'エアペイ' => 'airpay', 'スクエア' => 'square',
        'Square' => 'square', 'スマート払い' => 'smart_payment', 'スマート払' => 'smart_payment', '目黒区商品券' => 'gift_certificate',
        'ID' => 'id', 'iD' => 'id',
    ];

    public const BANDS = ['10_12' => 'C', '12_15' => 'G', '15_18' => 'K', '18_21' => 'O'];

    /** @var list<array<string,string>> */
    private array $rows = [];

    /** @var array{aggregate:int,manual_review:int,skipped:int,warning:int,error:int} */
    private array $counts = ['aggregate' => 0, 'manual_review' => 0, 'skipped' => 0, 'warning' => 0, 'error' => 0];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $manualReview = [];

    /** @var list<array<string,mixed>> */
    private array $legacyReported = [];

    /**
     * @param  array<string,mixed>  $options  fiscal_year / staff_label / course_mapping（旧コース名 => "type:id"）
     * @return array{rows: list<array<string,string>>, counts: array<string,int>, warnings: list<string>, manual_review: array<string,int>, legacy_reported: list<array<string,mixed>>}
     */
    public function convert(string $kind, string $path, array $options = []): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('変換種別が不正です。');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('原本ファイルを読み取れません。');
        }
        $this->rows = [];
        $this->counts = ['aggregate' => 0, 'manual_review' => 0, 'skipped' => 0, 'warning' => 0, 'error' => 0];
        $this->warnings = [];
        $this->manualReview = [];
        $this->legacyReported = [];

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        try {
            match ($kind) {
                'customer_list' => $this->customerList($book, $options),
                'staff_utilization' => $this->staffUtilization($book, $options),
                'time_band' => $this->timeBand($book),
                'attendance' => $this->attendance($book, $options),
                'daily_ledger' => $this->dailyLedger($book, $options),
                'monthly_sheet' => $this->monthlySheet($book),
            };
        } finally {
            $book->disconnectWorksheets();
        }

        return ['rows' => $this->rows, 'counts' => $this->counts, 'warnings' => array_values(array_unique($this->warnings)),
            'manual_review' => $this->manualReview, 'legacy_reported' => $this->legacyReported];
    }

    /** 中間形式CSV（UTF-8、ヘッダ付き）。既存の取込画面でプレビュー・stage・commitできる。 */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, self::HEADER, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => $row[$column] ?? '', self::HEADER), escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // ── 顧客データ一覧（新規統計ブック） ─────────────────────────────

    /** @param array<string,mixed> $options */
    private function customerList(Spreadsheet $book, array $options): void
    {
        $sheet = $book->getSheetByName('顧客データ一覧') ?? throw new InvalidArgumentException('顧客データ一覧シートがありません。');
        $headers = $this->headerMap($sheet, 2);
        foreach (['番号', '来店日', 'コース', '来店目的', '性別', '年代', '来店動機', '都道府県', '市区町村', '初回担当', '予約', '2回', '6回', '10回'] as $required) {
            if (! isset($headers[$required])) {
                throw new InvalidArgumentException("顧客データ一覧に「{$required}」列がありません。");
            }
        }
        $courseMapping = $options['course_mapping'] ?? [];
        $monthly = [];
        for ($row = 3, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $number = $this->text($sheet, $headers['番号'], $row);
            if ($number === '') {
                continue;
            }
            $date = $this->date($this->raw($sheet, $headers['来店日'].$row));
            if ($date === null) {
                $this->counts['warning']++;
                $this->warnings[] = '来店日が日付として読めない行は集計から除外（手動確認）';
                $this->review('来店日不明');
            }
            $channelLabel = $this->text($sheet, $headers['来店動機'], $row);
            $channel = $channelLabel === '' ? null : (self::CHANNEL_ALIASES[$channelLabel] ?? null);
            if ($channelLabel !== '' && $channel === null) {
                $this->review('来店動機: '.$channelLabel);
            }
            $purposeCodes = [];
            $purposeText = $this->text($sheet, $headers['来店目的'], $row);
            if ($purposeText !== '') {
                if (isset(self::PURPOSE_EXACT[$purposeText])) {
                    $purposeCodes[] = self::PURPOSE_EXACT[$purposeText];
                } else {
                    $this->review('来店目的: '.$purposeText);
                }
            }
            $course = $this->text($sheet, $headers['コース'], $row);
            if ($course !== '' && ! isset($courseMapping[$course])) {
                $this->review('コース: '.$course);
            }
            [$prefecture, $city] = $this->region($this->text($sheet, $headers['都道府県'], $row), $this->text($sheet, $headers['市区町村'], $row));
            $flags = [];
            foreach (['予約' => 'first_reservation', '2回' => 'reached_2', '6回' => 'reached_6', '10回' => 'reached_10'] as $label => $key) {
                $flags[$key] = $this->text($sheet, $headers[$label], $row) === '○';
            }
            $referrerColumn = $headers['その他来店動機　紹介者記入欄'] ?? null;
            $detail = [
                'first_visit_date' => $date?->toDateString(),
                'legacy_course' => $course === '' ? null : $course,
                'course' => $courseMapping[$course] ?? null,
                'visit_purpose_codes' => $purposeCodes,
                'gender' => match ($this->text($sheet, $headers['性別'], $row)) {
                    '男' => 'male', '女' => 'female', default => null
                },
                'age_decade_label' => $this->text($sheet, $headers['年代'], $row) ?: null,
                'acquisition_channel_code' => $channel,
                'has_referrer' => $referrerColumn !== null && $channel === 'referral' && $this->text($sheet, $referrerColumn, $row) !== '',
                'prefecture' => $prefecture,
                'city' => $city,
                'first_staff_label' => $this->text($sheet, $headers['初回担当'], $row) ?: null,
                ...$flags,
            ];
            $this->detail('customer_detail', $number, $detail);
            if ($date !== null) {
                $key = $date->format('Y-m');
                $monthly[$key] ??= ['new_customers' => 0, 'first_visit_reservation_count' => 0, 'reached_2' => 0, 'reached_6' => 0, 'reached_10' => 0, 'channels' => []];
                $monthly[$key]['new_customers']++;
                $monthly[$key]['first_visit_reservation_count'] += $flags['first_reservation'] ? 1 : 0;
                foreach ([2, 6, 10] as $threshold) {
                    $monthly[$key]["reached_{$threshold}"] += $flags["reached_{$threshold}"] ? 1 : 0;
                }
                $channelKey = $channel ?? 'unknown';
                $monthly[$key]['channels'][$channelKey] ??= ['new' => 0, 'reached_2' => 0];
                $monthly[$key]['channels'][$channelKey]['new']++;
                $monthly[$key]['channels'][$channelKey]['reached_2'] += $flags['reached_2'] ? 1 : 0;
            }
        }
        ksort($monthly);
        foreach ($monthly as $month => $values) {
            foreach (['new_customers', 'first_visit_reservation_count', 'reached_2', 'reached_6', 'reached_10'] as $metric) {
                $this->aggregate($metric, null, $month, $values[$metric]);
            }
            foreach ($values['channels'] as $channel => $channelValues) {
                $this->aggregate('new_customers', "channel:{$channel}", $month, $channelValues['new']);
                $this->aggregate('reached_2', "channel:{$channel}", $month, $channelValues['reached_2']);
            }
        }
        $this->newCustomerStats($book, $monthly);
    }

    /**
     * 「新規統計」シートの旧式の結果（COUNTIF等）は取り込まず、顧客データ一覧からの再集計値と並べて返す。
     *
     * @param  array<string, array<string, mixed>>  $monthly
     */
    private function newCustomerStats(Spreadsheet $book, array $monthly): void
    {
        $sheet = $book->getSheetByName('新規統計');
        if ($sheet === null) {
            return;
        }
        foreach ([1, 18] as $blockRow) {
            if (preg_match('/(\d{4})年4月/u', $this->text($sheet, 'B', $blockRow), $match) !== 1) {
                continue;
            }
            $fiscalYear = (int) $match[1];
            for ($index = 0; $index < 12; $index++) {
                $column = $this->column(2 + $index * 3);
                $month = CarbonImmutable::create($fiscalYear, 4, 1)->addMonths($index)->format('Y-m');
                $reported = $this->number($sheet, $column, $blockRow + 10);
                $reportedReturn = $this->number($sheet, $column, $blockRow + 12);
                if ($reported === null) {
                    continue;
                }
                $this->legacyReported[] = [
                    'source' => '新規統計', 'month' => $month, 'metric' => 'new_customers',
                    'legacy_value' => (int) $reported, 'recomputed_value' => $monthly[$month]['new_customers'] ?? 0,
                ];
                if ($reportedReturn !== null) {
                    $this->legacyReported[] = [
                        'source' => '新規統計', 'month' => $month, 'metric' => 'reached_2',
                        'legacy_value' => (int) $reportedReturn, 'recomputed_value' => $monthly[$month]['reached_2'] ?? 0,
                    ];
                }
            }
        }
    }

    // ── 稼働率（社員）年度ブック ──────────────────────────────────

    /** @param array<string,mixed> $options */
    private function staffUtilization(Spreadsheet $book, array $options): void
    {
        $fiscalYear = (int) ($options['fiscal_year'] ?? 0);
        if ($fiscalYear < 2000) {
            throw new InvalidArgumentException('稼働率の変換には --fiscal-year（4月始まりの年度）が必要です。');
        }
        foreach ($book->getWorksheetIterator() as $sheet) {
            if (preg_match('/^(\d{1,2})月$/u', $sheet->getTitle(), $match) !== 1) {
                $this->counts['skipped']++;

                continue;
            }
            $monthNumber = (int) $match[1];
            $month = CarbonImmutable::create($monthNumber >= 4 ? $fiscalYear : $fiscalYear + 1, $monthNumber, 1);
            $slot = 0;
            for ($col = 2, $highest = $this->columnIndex($sheet->getHighestDataColumn()); $col <= $highest; $col++) {
                if ($this->text($sheet, $this->column($col), 3) !== '稼働m') {
                    continue;
                }
                $name = $this->text($sheet, $this->column($col), 2);
                $slot++;
                $sums = ['staff_occupied_minutes' => 0, 'staff_patient_count' => 0, 'staff_working_minutes' => 0, 'staff_reservation_count' => 0, 'staff_nomination_count' => 0];
                $hasData = false;
                // 日は行位置（4行目=1日）。旧シートの1900年表示の日付セルは使わない。
                for ($day = 1; $day <= $month->daysInMonth; $day++) {
                    $row = $day + 3;
                    foreach (array_keys($sums) as $offset => $metric) {
                        $value = $this->number($sheet, $this->column($col + $offset), $row);
                        if ($value !== null) {
                            $sums[$metric] += (int) round($value);
                            $hasData = true;
                        }
                    }
                }
                if (! $hasData) {
                    $this->counts['skipped']++;

                    continue;
                }
                foreach ($sums as $metric => $value) {
                    $this->aggregate($metric, "staff_slot:{$slot}", $month->format('Y-m'), $value, $name);
                }
                // 旧月合計行（35行目）と月率（稼働率列）は式の結果なので比較用だけ。
                $reportedOccupied = $this->number($sheet, $this->column($col), 35);
                $reportedRate = $this->text($sheet, $this->column($col + 7), 3) === '稼働率' ? $this->number($sheet, $this->column($col + 7), 35) : null;
                $this->legacyReported[] = [
                    'source' => '稼働率', 'month' => $month->format('Y-m'), 'metric' => 'staff_occupied_minutes', 'dimension' => "staff_slot:{$slot}",
                    'legacy_value' => $reportedOccupied === null ? null : (int) round($reportedOccupied), 'recomputed_value' => $sums['staff_occupied_minutes'],
                ];
                $this->legacyReported[] = [
                    'source' => '稼働率', 'month' => $month->format('Y-m'), 'metric' => 'legacy_utilization_rate', 'dimension' => "staff_slot:{$slot}",
                    'legacy_value' => $reportedRate, 'recomputed_value' => $sums['staff_working_minutes'] === 0 ? null : $sums['staff_occupied_minutes'] / $sums['staff_working_minutes'],
                ];
            }
        }
    }

    // ── 時間帯別稼働率 ────────────────────────────────────────

    private function timeBand(Spreadsheet $book): void
    {
        $sheet = $book->getSheet(0);
        $monthly = [];
        for ($row = 3, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $date = $this->date($this->raw($sheet, 'A'.$row));
            if ($date === null) {
                continue;
            }
            $dayType = $date->dayOfWeekIso <= 5 ? 'weekday' : 'weekend';
            foreach (self::BANDS as $band => $capacityColumn) {
                $index = $this->columnIndex($capacityColumn);
                $capacity = $this->number($sheet, $capacityColumn, $row);
                $occupied = $this->number($sheet, $this->column($index + 1), $row);
                foreach ([$capacityColumn, $this->column($index + 1), $this->column($index + 3)] as $inputColumn) {
                    $formula = $sheet->getCell($inputColumn.$row)->getValue();
                    if (is_string($formula) && str_starts_with($formula, '=')) {
                        // 旧シートは手計算の式（例 =15+90+60）を入力欄に書いている。結果値を入力値として使い、件数を警告する。
                        $this->counts['warning']++;
                        $this->warnings[] = '入力欄の手計算式（例 =15+90+60）は保存済みの結果値を入力値として使用';
                    }
                }
                $visits = $this->number($sheet, $this->column($index + 3), $row);
                if ($capacity === null && $occupied === null) {
                    continue;
                }
                foreach (["band:{$band}", "band:{$band}|{$dayType}"] as $dimension) {
                    $key = $date->format('Y-m').'|'.$dimension;
                    $monthly[$key] ??= ['band_capacity_minutes' => 0, 'band_occupied_minutes' => 0, 'band_visit_count' => 0, 'days' => 0];
                    $monthly[$key]['band_capacity_minutes'] += (int) round($capacity ?? 0);
                    $monthly[$key]['band_occupied_minutes'] += (int) round($occupied ?? 0);
                    $monthly[$key]['band_visit_count'] += (int) round($visits ?? 0);
                    $monthly[$key]['days']++;
                }
            }
        }
        ksort($monthly);
        foreach ($monthly as $key => $values) {
            [$month, $dimension] = explode('|', $key, 2);
            foreach (['band_capacity_minutes', 'band_occupied_minutes', 'band_visit_count'] as $metric) {
                $this->aggregate($metric, $dimension, $month, $values[$metric]);
            }
        }
        // 旧右側の月集計（平日・土日の帯別「稼働率」＝日率の平均）は比較用だけ。
        foreach (['weekday' => 3, 'weekend' => 18] as $dayType => $firstRow) {
            for ($offset = 0; $offset < 12; $offset++) {
                $label = $this->text($sheet, 'T', $firstRow + $offset);
                if (preg_match('/^(\d{1,2})月$/u', $label, $match) !== 1) {
                    continue;
                }
                foreach (['10_12' => 'U', '12_15' => 'W', '15_18' => 'Y', '18_21' => 'AA'] as $band => $column) {
                    $legacy = $this->number($sheet, $column, $firstRow + $offset);
                    if ($legacy === null) {
                        continue;
                    }
                    $monthKey = collect(array_keys($monthly))->first(fn (string $key): bool => str_ends_with($key, "|band:{$band}|{$dayType}")
                        && (int) substr($key, 5, 2) === (int) $match[1]);
                    $values = $monthKey === null ? null : $monthly[$monthKey];
                    $this->legacyReported[] = [
                        'source' => '時間帯別稼働率', 'month' => $monthKey === null ? $label : substr($monthKey, 0, 7), 'metric' => 'band_rate',
                        'dimension' => "band:{$band}|{$dayType}", 'legacy_value' => $legacy,
                        'recomputed_value' => $values === null || $values['band_capacity_minutes'] === 0 ? null : $values['band_occupied_minutes'] / $values['band_capacity_minutes'],
                    ];
                }
            }
        }
    }

    // ── 出勤簿 ──────────────────────────────────────────────

    /** @param array<string,mixed> $options */
    private function attendance(Spreadsheet $book, array $options): void
    {
        $label = trim((string) ($options['staff_label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('出勤簿の変換には --staff-label（スタッフの識別名）が必要です。');
        }
        foreach ($book->getWorksheetIterator() as $sheet) {
            $year = $this->number($sheet, 'A', 6);
            $monthNumber = $this->number($sheet, 'B', 6);
            if ($year === null || $monthNumber === null || $sheet->getTitle() === '原本') {
                $this->counts['skipped']++;

                continue;
            }
            $month = CarbonImmutable::create((int) $year, (int) $monthNumber, 1);
            $working = 0;
            $breaks = 0;
            $days = 0;
            for ($day = 1; $day <= $month->daysInMonth; $day++) {
                $row = $day + 7;
                $start = $this->minutesOfDay($this->raw($sheet, 'C'.$row));
                $end = $this->minutesOfDay($this->raw($sheet, 'E'.$row));
                if ($start === null || $end === null) {
                    continue;
                }
                $rawBreak = $this->raw($sheet, 'G'.$row);
                $break = $rawBreak === null || $rawBreak === '' ? null : $this->durationMinutes($rawBreak);
                $detail = ['business_date' => $month->setDay($day)->toDateString(), 'start' => $this->hhmm($start), 'end' => $this->hhmm($end),
                    'break_minutes' => $break, 'working_minutes' => $break === null ? null : $end - $start - $break];
                $this->detail('legacy_attendance_detail', $label, $detail);
                $days++;
                if ($break === null) {
                    // 旧出勤簿は休憩欄が空の日に過不足を計算しない。ARKは休憩を0とみなさず、その日の労働分を未確定として除外する。
                    $this->counts['warning']++;
                    $this->warnings[] = '休憩欄が空の日は労働時間を未確定として集計から除外';

                    continue;
                }
                $working += $end - $start - $break;
                $breaks += $break;
            }
            if ($days === 0) {
                continue;
            }
            $this->aggregate('attendance_working_minutes', "staff:{$label}", $month->format('Y-m'), $working);
            $this->aggregate('attendance_break_minutes', "staff:{$label}", $month->format('Y-m'), $breaks);
            $this->aggregate('attendance_days', "staff:{$label}", $month->format('Y-m'), $days);
        }
    }

    // ── 日計表（R8.x）と分析シート ─────────────────────────────

    /** @param array<string,mixed> $options */
    private function dailyLedger(Spreadsheet $book, array $options): void
    {
        $courseMapping = $options['course_mapping'] ?? [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $title = $sheet->getTitle();
            if ($title === '分析') {
                $this->analysisSheet($sheet);

                continue;
            }
            if ($this->text($sheet, 'C', 3) !== '日付' || $this->text($sheet, 'D', 3) !== '顧客番号') {
                $this->counts['skipped']++;

                continue;
            }
            $monthly = [];
            for ($row = 4, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
                $date = $this->date($this->raw($sheet, 'C'.$row));
                $customer = $this->text($sheet, 'D', $row);
                if ($date === null || $customer === '') {
                    continue;
                }
                $menus = $this->menus($this->text($sheet, 'F', $row));
                $minutes = $menus === null ? null : array_sum(array_column($menus, 'minutes'));
                $staff = $this->staffEntries($this->text($sheet, 'H', $row));
                $course = $this->text($sheet, 'I', $row);
                if ($course !== '' && ! isset($courseMapping[$course])) {
                    $this->review('コース: '.$course);
                }
                $treatmentMethod = $this->paymentCode($this->text($sheet, 'N', $row));
                $retailMethod = $this->paymentCode($this->text($sheet, 'O', $row));
                $treatmentAmount = $this->number($sheet, 'L', $row);
                $retailAmount = $this->number($sheet, 'M', $row);
                $detail = [
                    'business_date' => $date->toDateString(), 'menus' => $menus, 'treatment_minutes' => $minutes,
                    'is_long' => $minutes === null ? null : $minutes > 60, 'staff' => $staff,
                    'legacy_course' => $course === '' ? null : $course, 'course' => $courseMapping[$course] ?? null,
                    'ticket_use_number' => $this->text($sheet, 'G', $row) ?: null,
                    'treatment_amount' => $treatmentAmount === null ? null : (int) $treatmentAmount, 'treatment_payment_method' => $treatmentMethod,
                    'retail_items' => $this->text($sheet, 'J', $row) ?: null,
                    'retail_amount' => $retailAmount === null ? null : (int) $retailAmount, 'retail_payment_method' => $retailMethod,
                    'next_reservation' => $this->bool($this->raw($sheet, 'P'.$row)),
                    'new_mark' => $this->text($sheet, 'Q', $row) !== '',
                ];
                $this->detail('legacy_visit_detail', $customer, $detail);
                $key = $date->format('Y-m');
                $monthly[$key] ??= ['visit_count' => 0, 'long_visit_count' => 0, 'future_reservation_count' => 0, 'first_visit_count' => 0, 'treatment' => [], 'retail' => []];
                $monthly[$key]['visit_count']++;
                $monthly[$key]['long_visit_count'] += $minutes !== null && $minutes > 60 ? 1 : 0;
                $monthly[$key]['future_reservation_count'] += $detail['next_reservation'] === true ? 1 : 0;
                $monthly[$key]['first_visit_count'] += $detail['new_mark'] ? 1 : 0;
                if ($treatmentAmount !== null && $treatmentMethod !== null) {
                    $monthly[$key]['treatment'][$treatmentMethod] = ($monthly[$key]['treatment'][$treatmentMethod] ?? 0) + (int) $treatmentAmount;
                }
                if ($retailAmount !== null && $retailMethod !== null) {
                    $monthly[$key]['retail'][$retailMethod] = ($monthly[$key]['retail'][$retailMethod] ?? 0) + (int) $retailAmount;
                }
                if ($menus === null && $this->text($sheet, 'F', $row) !== '') {
                    $this->counts['warning']++;
                    $this->warnings[] = 'メニューコードを解釈できない行はロング判定をしない（手動確認）';
                }
            }
            foreach ($monthly as $month => $values) {
                foreach (['visit_count', 'long_visit_count', 'future_reservation_count', 'first_visit_count'] as $metric) {
                    $this->aggregate($metric, "sheet:{$title}", $month, $values[$metric]);
                }
                foreach ($values['treatment'] as $method => $amount) {
                    $this->aggregate('treatment_payment_amount', "method:{$method}", $month, $amount);
                }
                foreach ($values['retail'] as $method => $amount) {
                    $this->aggregate('retail_payment_amount', "method:{$method}", $month, $amount);
                }
            }
        }
    }

    /**
     * 分析シートの月別店舗値は、当時の月計表（税抜の売上金・来店数・初診数）をIMPORTRANGEしたもの。
     * 明細の無い月の集計値として取り込む。参照列が途中で変わっている月（M/N列参照）は手動確認。
     */
    private function analysisSheet(Worksheet $sheet): void
    {
        for ($row = 18; $row <= 45; $row++) {
            $label = $this->text($sheet, 'B', $row);
            if (preg_match('/^R(\d+)\.(\d{1,2})$/u', $label, $match) !== 1) {
                continue;
            }
            $month = CarbonImmutable::create(2018 + (int) $match[1], (int) $match[2], 1)->format('Y-m');
            $sales = $this->number($sheet, 'C', $row);
            if ($sales === null) {
                continue;
            }
            if (in_array($label, ['R6.9', 'R6.10', 'R6.11'], true)) {
                $this->review('分析シート: 参照列が他の月と異なる月（'.$label.'）');
                $this->counts['warning']++;

                continue;
            }
            $this->aggregate('net_sales', null, $month, (int) round($sales));
            $visits = $this->number($sheet, 'E', $row);
            if ($visits !== null) {
                $this->aggregate('visit_count', null, $month, (int) round($visits));
            }
            $first = $this->number($sheet, 'G', $row);
            if ($first !== null) {
                $this->aggregate('first_visit_count', null, $month, (int) round($first));
            }
        }
    }

    // ── 月計表（原本テンプレート形式） ───────────────────────────

    private function monthlySheet(Spreadsheet $book): void
    {
        $sheet = $book->getSheetByName('月計表') ?? throw new InvalidArgumentException('月計表シートがありません。');
        $year = $this->number($sheet, 'C', 1);
        $monthNumber = $this->number($sheet, 'D', 1);
        if ($year === null || $monthNumber === null) {
            throw new InvalidArgumentException('月計表のC1/D1に年月がありません。');
        }
        $month = CarbonImmutable::create((int) $year, (int) $monthNumber, 1);
        $methods = ['C' => 'cash', 'D' => 'paypay', 'E' => 'airpay', 'F' => 'square', 'G' => 'smart_payment', 'H' => 'gift_certificate', 'I' => 'id'];
        $retail = ['K' => 'cash', 'L' => 'paypay', 'M' => 'airpay', 'N' => 'id'];
        $totals = [];
        $filled = 0;
        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $row = $day + 2;
            foreach ([...array_map(fn (string $m): string => "treatment_payment_amount|method:{$m}", $methods), ...array_map(fn (string $m): string => "retail_payment_amount|method:{$m}", $retail),
                'R' => 'visit_count|', 'S' => 'long_visit_count|', 'T' => 'future_reservation_count|', 'V' => 'first_visit_count|', 'W' => 'first_visit_reservation_count|'] as $column => $key) {
                $value = $this->number($sheet, $column, $row);
                if ($value !== null) {
                    $totals[$key] = ($totals[$key] ?? 0) + (int) round($value);
                    $filled++;
                }
            }
        }
        if ($filled === 0) {
            $this->warnings[] = '月計表に実績の入力がありません（テンプレートのみ）';

            return;
        }
        foreach ($totals as $key => $value) {
            [$metric, $dimension] = explode('|', $key);
            $this->aggregate($metric, $dimension === '' ? null : $dimension, $month->format('Y-m'), $value);
        }
        // J/P/Q（固定税率の式）は取り込まない。税抜はARKの会計明細snapshotが正本。
        $this->warnings[] = '月計表J/P/Q（固定税率の式）の税抜は取り込まず、決済別の入力値だけを取り込む';
    }

    // ── helpers ─────────────────────────────────────────────

    private function aggregate(string $metric, ?string $dimension, string $month, int $value, ?string $sourceIdentifier = null): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $this->rows[] = [
            'record_type' => 'historical_aggregate', 'metric_code' => $metric, 'dimension' => $dimension ?? '',
            'period_start' => $start->toDateString(), 'period_end' => $start->endOfMonth()->toDateString(),
            'value' => (string) $value, 'source_identifier' => $sourceIdentifier ?? '', 'detail' => '',
        ];
        $this->counts['aggregate']++;
    }

    /** @param array<string,mixed> $detail */
    private function detail(string $type, string $sourceIdentifier, array $detail): void
    {
        $this->rows[] = [
            'record_type' => $type, 'metric_code' => '', 'dimension' => '', 'period_start' => '', 'period_end' => '', 'value' => '',
            'source_identifier' => $sourceIdentifier,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        $this->counts['manual_review']++;
    }

    private function review(string $item): void
    {
        $this->manualReview[$item] = ($this->manualReview[$item] ?? 0) + 1;
    }

    /** @return array<string,string> 見出し => 列 */
    private function headerMap(Worksheet $sheet, int $row): array
    {
        $map = [];
        for ($col = 1, $highest = $this->columnIndex($sheet->getHighestDataColumn()); $col <= $highest; $col++) {
            $label = preg_replace('/\s+/u', '', $this->text($sheet, $this->column($col), $row)) ?? '';
            if ($label !== '') {
                $map[$label] ??= $this->column($col);
            }
        }
        // 「その他来店動機　紹介者記入欄」は全角空白・改行を含むため空白を除いた名前でも引けるようにする。
        foreach ($map as $label => $column) {
            if (str_starts_with($label, 'その他来店動機')) {
                $map['その他来店動機　紹介者記入欄'] = $column;
            }
        }

        return $map;
    }

    /** 式セルは実行せず、ファイルに保存されている計算結果（キャッシュ値）を読む。 */
    private function raw(Worksheet $sheet, string $coordinate): mixed
    {
        $cell = $sheet->getCell($coordinate);
        $value = $cell->getValue();
        if (is_string($value) && str_starts_with($value, '=')) {
            return $cell->getOldCalculatedValue();
        }

        return $value;
    }

    private function text(Worksheet $sheet, string $column, int $row): string
    {
        $value = $this->raw($sheet, $column.$row);

        // trim()の文字リストに全角空白を渡すとUTF-8のバイト単位で削れるため、正規表現で前後の空白だけを落とす。
        return is_scalar($value) ? (preg_replace('/^[\s　]+|[\s　]+$/u', '', (string) $value) ?? '') : '';
    }

    private function number(Worksheet $sheet, string $column, int $row): ?float
    {
        $value = $this->raw($sheet, $column.$row);

        return is_int($value) || is_float($value) ? (float) $value : (is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : null);
    }

    private function bool(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 'TRUE', $value === 1 => true,
            $value === false, $value === 'FALSE', $value === 0 => false,
            default => null,
        };
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) || is_float($value)) {
            return $value < 30000 ? null : CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))->startOfDay();
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) === 1) {
            return CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3]);
        }

        return null;
    }

    private function minutesOfDay(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 && $value < 1 ? (int) round($value * 1440) : null;
        }
        if (is_string($value) && preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $match) === 1) {
            return (int) $match[1] * 60 + (int) $match[2];
        }

        return null;
    }

    private function durationMinutes(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) round($value * 1440) : $this->minutesOfDay($value);
    }

    private function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @return list<array{category:string,minutes:int}>|null 例: "T45, M15" */
    private function menus(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $menus = [];
        foreach (preg_split('/[,、]\s*/u', $value) ?: [] as $token) {
            if (preg_match('/^([MTA])(\d{2,3})$/u', trim($token), $match) !== 1) {
                return null;
            }
            $menus[] = ['category' => $match[1], 'minutes' => (int) $match[2]];
        }

        return $menus;
    }

    /** @return list<array{label:string,nominated:bool}> 例: "奨(指), 齋" */
    private function staffEntries(string $value): array
    {
        $entries = [];
        foreach (preg_split('/[,、]\s*/u', $value) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $nominated = str_contains($token, '(指)') || str_contains($token, '（指）');
            $entries[] = ['label' => trim(str_replace(['(指)', '（指）'], '', $token)), 'nominated' => $nominated];
        }

        return $entries;
    }

    private function paymentCode(string $label): ?string
    {
        if ($label === '') {
            return null;
        }
        $code = self::PAYMENT_ALIASES[$label] ?? null;
        if ($code === null) {
            $this->review('支払方法: '.$label);
        }

        return $code;
    }

    /** @return array{0:?string,1:?string} 市区町村は「区・市・町・村」までで切り、番地・建物名は持たない。 */
    private function region(string $prefecture, string $address): array
    {
        $prefecture = trim(str_replace('　', '', $prefecture));
        $prefecture = match ($prefecture) {
            '', '不明', '未記入' => null, '神奈川' => '神奈川県', default => $prefecture
        };
        if ($address === '' || $address === '0') {
            return [$prefecture, null];
        }
        if (preg_match('/^(.+?[市区町村])/u', $address, $match) === 1) {
            $city = $match[1];
            // 「川崎市中原区」のような政令市の区は区まで残す。
            if (preg_match('/^(.+?市.+?区)/u', $address, $ward) === 1) {
                $city = $ward[1];
            }

            return [$prefecture, mb_substr($city, 0, 50)];
        }

        return [$prefecture, null];
    }

    private function column(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function columnIndex(string $column): int
    {
        return Coordinate::columnIndexFromString($column);
    }
}
