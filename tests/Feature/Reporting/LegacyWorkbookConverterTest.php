<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\HistoricalImportService;
use App\Domain\Reporting\Import\LegacyWorkbookConverter;
use App\Domain\Reporting\ReportReconciliationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** 合成した旧帳票ブック（架空データ）で変換規則を検証する。実物サンプルはリポジトリに含めない。 */
class LegacyWorkbookConverterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_customer_list_keeps_only_analysis_fields_and_recomputes_from_rows(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet()->setTitle('顧客データ一覧');
            $sheet->fromArray(['番号', '来店日', '名前', 'フリガナ', 'コース', 'その他コース記入欄', '来店目的', '性別', '年代', '来店動機',
                "その他来店動機\n紹介者記入欄", '都道府県', '市区町村', '初回担当', '予約', '2回', '6回', '10回'], null, 'A2');
            $sheet->fromArray(['101', ExcelDate::stringToExcel('2026-04-03'), '架空 太郎', 'カクウ タロウ', 'マッサージ', '', '痛みを取りたい', '男', '30代', 'ホトぺ',
                '', '東京都', '目黒区自由が丘9-9-9 架空ビル', '担当A', '○', '○', '', ''], null, 'A3');
            $sheet->fromArray(['102', ExcelDate::stringToExcel('2026-04-10'), '架空 花子', 'カクウ ハナコ', 'トレーニング', '', '痛み取りたい', '女', '40代', '紹介',
                '架空の紹介者', '神奈川県', '川崎市中原区架空1-1', '担当B', '', '', '', ''], null, 'A4');
            $stats = $book->createSheet()->setTitle('新規統計');
            $stats->setCellValue('B1', '2026年4月～2027年3月');
            $stats->setCellValue('B11', 5);
            $stats->setCellValue('B13', 1);
        });

        $result = app(LegacyWorkbookConverter::class)->convert('customer_list', $path);
        $csv = app(LegacyWorkbookConverter::class)->toCsv($result['rows']);

        $this->assertSame(2, $result['counts']['manual_review']);
        $this->assertStringNotContainsString('架空 太郎', $csv);
        $this->assertStringNotContainsString('カクウ', $csv);
        $this->assertStringNotContainsString('9-9-9', $csv);
        $this->assertStringNotContainsString('架空の紹介者', $csv);
        $detail = json_decode($result['rows'][0]['detail'], true);
        $this->assertSame(['東京都', '目黒区', 'hotpepper', ['pain_relief']], [$detail['prefecture'], $detail['city'], $detail['acquisition_channel_code'], $detail['visit_purpose_codes']]);
        $this->assertSame('川崎市中原区', json_decode($result['rows'][1]['detail'], true)['city']);
        $this->assertTrue(json_decode($result['rows'][1]['detail'], true)['has_referrer']);
        $this->assertSame(1, $result['manual_review']['来店目的: 痛み取りたい']);
        $this->assertSame(1, $result['manual_review']['コース: マッサージ']);

        $aggregates = collect($result['rows'])->where('record_type', 'historical_aggregate')->keyBy(fn (array $row): string => $row['metric_code'].'|'.$row['dimension']);
        $this->assertSame('2', $aggregates['new_customers|']['value']);
        $this->assertSame('1', $aggregates['reached_2|']['value']);
        $this->assertSame('1', $aggregates['new_customers|channel:hotpepper']['value']);
        $this->assertSame(['legacy' => 5, 'recomputed' => 2], [
            'legacy' => $result['legacy_reported'][0]['legacy_value'], 'recomputed' => $result['legacy_reported'][0]['recomputed_value'],
        ]);
    }

    public function test_time_band_sums_inputs_and_reports_legacy_average_of_rates_separately(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('C1', '10~12(120)');
            // 平日2日: 1日目 30/120、2日目 90/120 → 旧式は日率平均 50%、ARKは合計比 120/240 = 50%（同じ）。
            // 土日1日: 60/240。旧右側は別の値（例 0.9）を持たせ、比較用にだけ返ることを確認する。
            $sheet->fromArray([ExcelDate::stringToExcel('2026-04-01'), '水', 120, 30, '=D3/C3', 1], null, 'A3');
            $sheet->fromArray([ExcelDate::stringToExcel('2026-04-02'), '木', 120, '=45+45', '=D4/C4', 2], null, 'A4');
            $sheet->fromArray([ExcelDate::stringToExcel('2026-04-04'), '土', 240, 60, '=D5/C5', 1], null, 'A5');
            $sheet->setCellValue('T3', '4月');
            $sheet->setCellValue('U3', 0.9);
        });
        // 手計算の式セル（=45+45）は保存済みの計算結果を使う。openpyxl等の書出しと同様にキャッシュ値を入れておく。
        $book = IOFactory::load($path);
        $book->getActiveSheet()->getCell('D4')->setCalculatedValue(90);
        (new Xlsx($book))->setPreCalculateFormulas(true)->save($path);

        $result = app(LegacyWorkbookConverter::class)->convert('time_band', $path);
        $rows = collect($result['rows'])->keyBy(fn (array $row): string => $row['metric_code'].'|'.$row['dimension']);
        $this->assertSame('120', $rows['band_occupied_minutes|band:10_12|weekday']['value']);
        $this->assertSame('240', $rows['band_capacity_minutes|band:10_12|weekday']['value']);
        $this->assertSame('3', $rows['band_visit_count|band:10_12|weekday']['value']);
        $this->assertSame('60', $rows['band_occupied_minutes|band:10_12|weekend']['value']);
        $this->assertGreaterThan(0, $result['counts']['warning']);
        $reported = collect($result['legacy_reported'])->firstWhere('dimension', 'band:10_12|weekday');
        $this->assertSame(0.9, $reported['legacy_value']);
        $this->assertSame(0.5, $reported['recomputed_value']);
    }

    public function test_attendance_does_not_treat_blank_break_as_zero(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet()->setTitle('R8.9');
            $sheet->setCellValue('A6', 2026)->setCellValue('B6', 9);
            $sheet->fromArray([1, '火', 10 / 24, null, 19 / 24, null, 1 / 24], null, 'A8');
            $sheet->fromArray([2, '水', 10 / 24, null, 21 / 24], null, 'A9');
        });

        $result = app(LegacyWorkbookConverter::class)->convert('attendance', $path, ['staff_label' => 'A']);
        $rows = collect($result['rows'])->where('record_type', 'historical_aggregate')->keyBy('metric_code');
        $this->assertSame('480', $rows['attendance_working_minutes']['value']);
        $this->assertSame('2', $rows['attendance_days']['value']);
        $this->assertSame(1, $result['counts']['warning']);
        $this->assertNull(json_decode(collect($result['rows'])->where('record_type', 'legacy_attendance_detail')->last()['detail'], true)['working_minutes']);

        $this->expectException(InvalidArgumentException::class);
        app(LegacyWorkbookConverter::class)->convert('attendance', $path);
    }

    public function test_daily_ledger_maps_menus_payment_methods_and_nominations_for_review(): void
    {
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet()->setTitle('R8.9');
            $sheet->fromArray(['No.', '日付', '顧客番号', '名前', 'メニュー', '回数券', '担当', 'コース', '物販', '支払', '施術金額', '物販金額', '施術支払方法', '物販支払方法', '予約', '新規'], null, 'B3');
            $sheet->fromArray([1, ExcelDate::stringToExcel('2026-09-01'), '501', '架空', 'T45, M15', '2', '奨(指), 齋', '8回券60分', '水', '1', 44000, 100, 'AirPAY', '現金', true, '⚫︎'], null, 'B4');
            $sheet->fromArray([2, ExcelDate::stringToExcel('2026-09-01'), '502', '架空', 'T60, M30', '', '慈', '一般60分', '', '', null, null, '', '', false, ''], null, 'B5');
        });

        $result = app(LegacyWorkbookConverter::class)->convert('daily_ledger', $path, ['course_mapping' => ['8回券60分' => 'ticket:1']]);
        $details = collect($result['rows'])->where('record_type', 'legacy_visit_detail')->values();
        $first = json_decode($details[0]['detail'], true);
        $this->assertSame(60, $first['treatment_minutes']);
        $this->assertFalse($first['is_long']);
        $this->assertSame([['label' => '奨', 'nominated' => true], ['label' => '齋', 'nominated' => false]], $first['staff']);
        $this->assertSame(['airpay', 'cash', 'ticket:1'], [$first['treatment_payment_method'], $first['retail_payment_method'], $first['course']]);
        $this->assertTrue(json_decode($details[1]['detail'], true)['is_long']);
        $this->assertSame(1, $result['manual_review']['コース: 一般60分']);
        $aggregates = collect($result['rows'])->where('record_type', 'historical_aggregate')->keyBy(fn (array $row): string => $row['metric_code'].'|'.$row['dimension']);
        $this->assertSame('2', $aggregates['visit_count|sheet:R8.9']['value']);
        $this->assertSame('1', $aggregates['long_visit_count|sheet:R8.9']['value']);
        $this->assertSame('44000', $aggregates['treatment_payment_amount|method:airpay']['value']);
        $this->assertSame('100', $aggregates['retail_payment_amount|method:cash']['value']);
    }

    public function test_converted_csv_stages_details_for_review_and_commits_dimensioned_aggregates(): void
    {
        Storage::fake('local');
        $path = $this->workbook(function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet();
            $sheet->fromArray([ExcelDate::stringToExcel('2026-04-01'), '水', 120, 30, null, 1], null, 'A3');
        });
        $converter = app(LegacyWorkbookConverter::class);
        $csv = $converter->toCsv($converter->convert('time_band', $path)['rows']);
        $user = User::factory()->create();
        $service = app(HistoricalImportService::class);
        $staged = $service->stage(UploadedFile::fake()->createWithContent('time_band.csv', $csv), $user->id);
        $service->commit($staged['batch_id']);

        $this->assertSame('band:10_12', DB::table('historical_metric_values')->where('metric_code', 'band_occupied_minutes')->orderBy('id')->value('dimension'));
        $comparison = app(ReportReconciliationService::class)->forMonth(2026, 4, $staged['batch_id']);
        $row = collect($comparison['comparisons'])->firstWhere('metric_code', 'band_occupied_minutes');
        $this->assertSame('not_comparable', $row['comparison_status']);
    }

    private function workbook(callable $build): string
    {
        $book = new Spreadsheet;
        $build($book);
        $path = tempnam(sys_get_temp_dir(), 'legacy-').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->files[] = $path;

        return $path;
    }
}
