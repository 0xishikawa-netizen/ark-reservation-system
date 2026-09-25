<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Excel\ArkSixSheetCellMap;
use App\Domain\Reporting\Excel\LegacySixSheetCellMap;
use App\Domain\Reporting\Excel\ReportWorkbookService;
use App\Domain\Reporting\Excel\WorkbookTemplateRegistry;
use App\Domain\Reporting\MonthlyReportService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Enums\Reporting\SalesBasis;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\EmploymentType;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\StaffEmploymentPeriod;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

final class ReportWorkbookServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('report_export.template_file', null);
        config()->set('report_export.template_sha256', null);
    }

    public function test_provided_original_is_read_only_template_and_ark_values_replace_business_formulas(): void
    {
        config()->set('report_export.template_file', 'ark-jiyugaoka-2026-10-source.xlsx');
        config()->set('report_export.template_sha256', '739c6dd1655ced0e4f7c47c3cb7e595a2f52d78ba3a8480fe55d2f438268c9d6');
        $source = resource_path('report_templates/ark-jiyugaoka-2026-10-source.xlsx');
        $before = hash_file('sha256', $source);
        $method = PaymentMethod::factory()->create(['name' => '現金']);
        $this->checkout('2026-10-10', $method, 1100);
        $this->checkout('2026-10-20', $method, 2200);

        $export = app(ReportWorkbookService::class)->build(2026, 10, asOfDate: '2026-10-15');
        $book = $export['workbook'];
        $this->assertSame(LegacySixSheetCellMap::SHEETS, $book->getSheetNames());
        $this->assertSame('ARK自由が丘店2026.10.xlsx', $export['filename']);
        $this->assertSame('739c6dd1655ced0e4f7c47c3cb7e595a2f52d78ba3a8480fe55d2f438268c9d6', $before);
        $this->assertSame($before, hash_file('sha256', $source));
        $this->assertSame('A14:A18', (string) $book->getSheetByName('数値')->getMergeCells()['A14:A18']);
        $this->assertSame(17.88, $book->getSheetByName('数値')->getColumnDimension('A')->getWidth());
        $this->assertSame(1100, $book->getSheetByName('月計表')->getCell('Q12')->getValue());
        $this->assertSame(1100, $book->getSheetByName('月計表')->getCell('J12')->getValue());
        $this->assertSame(1100, $book->getSheetByName('月計表')->getCell('C34')->getValue());
        $this->assertSame(100, $book->getSheetByName('月計表')->getCell('P34')->getValue());
        $this->assertSame(1, $book->getSheetByName('月計表')->getCell('AD34')->getValue());
        $this->assertNull($book->getSheetByName('月計表')->getCell('Q22')->getValue());
        $this->assertNull($book->getSheetByName('月計表')->getCell('P22')->getValue());
        $this->assertNull($book->getSheetByName('月計表')->getCell('U22')->getValue());
        $this->assertSame(1100, $book->getSheetByName('月計表')->getCell('Q34')->getValue());
        $this->assertSame(1, $book->getSheetByName('月計表')->getCell('R34')->getValue());
        $this->assertSame(1100, $book->getSheetByName('数値')->getCell('K13')->getValue());
        $this->assertNull($book->getSheetByName('数値')->getCell('K23')->getValue());
        $this->assertSame('ARK自由が丘店 2026年度計画・実績', $book->getSheetByName('年間計画書 (実数)')->getCell('A1')->getValue());
        $this->assertSame(1100, $book->getSheetByName('年間計画書 (実数)')->getCell('O5')->getValue());
        $this->assertSame(61.5, $book->getSheetByName('日報')->getRowDimension(3)->getRowHeight());
        $path = tempnam(sys_get_temp_dir(), 'ark-legacy-report-');
        try {
            (new Xlsx($book))->save($path);
            $reloaded = IOFactory::load($path);
            $this->assertSame(LegacySixSheetCellMap::SHEETS, $reloaded->getSheetNames());
            $this->assertSame(1100, $reloaded->getSheetByName('月計表')->getCell('Q12')->getValue());
            $this->assertNull($reloaded->getSheetByName('月計表')->getCell('Q22')->getValue());
            $this->assertSame(17.88, $reloaded->getSheetByName('数値')->getColumnDimension('A')->getWidth());
            $this->assertSame(61.5, $reloaded->getSheetByName('日報')->getRowDimension(3)->getRowHeight());
            $this->assertArrayHasKey('A14:A18', $reloaded->getSheetByName('数値')->getMergeCells());
            $original = IOFactory::load($source);
            foreach (LegacySixSheetCellMap::SHEETS as $name) {
                $sourceSheet = $original->getSheetByName($name);
                $outputSheet = $reloaded->getSheetByName($name);
                $sourceMerges = array_keys($sourceSheet->getMergeCells());
                $outputMerges = array_keys($outputSheet->getMergeCells());
                sort($sourceMerges);
                sort($outputMerges);
                $this->assertSame($sourceMerges, $outputMerges, $name.' merges');
                $this->assertSame($sourceSheet->getColumnDimension('C')->getWidth(), $outputSheet->getColumnDimension('C')->getWidth(), $name.' width');
                $this->assertSame($sourceSheet->getRowDimension(4)->getRowHeight(), $outputSheet->getRowDimension(4)->getRowHeight(), $name.' height');
                if (str_starts_with($name, '稼働率')) {
                    $this->assertSame($sourceSheet->getCell('H5')->getStyle()->getNumberFormat()->getFormatCode(),
                        $outputSheet->getCell('H5')->getStyle()->getNumberFormat()->getFormatCode(), $name.' rate format');
                }
            }
            $original->disconnectWorksheets();
            foreach ($reloaded->getAllSheets() as $sheet) {
                $remainingFormulas = 0;
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    $remainingFormulas += (int) $sheet->getCell($coordinate)->isFormula();
                }
                $this->assertSame(0, $remainingFormulas, $sheet->getTitle());
            }
            $reloaded->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->assertSame($before, hash_file('sha256', $source));
        $book->disconnectWorksheets();
    }

    public function test_provided_template_clears_nonexistent_days_and_preserves_fiscal_month_positions(): void
    {
        config()->set('report_export.template_file', 'ark-jiyugaoka-2026-10-source.xlsx');
        config()->set('report_export.template_sha256', '739c6dd1655ced0e4f7c47c3cb7e595a2f52d78ba3a8480fe55d2f438268c9d6');
        $this->assertCount(28, app(MonthlyReportService::class)->forMonth(2026, 2, asOfDate: '2026-02-15')->dailyRows);
        $export = app(ReportWorkbookService::class)->build(2026, 2, asOfDate: '2026-02-15');
        $book = $export['workbook'];
        $this->assertSame(28, $book->getSheetByName('月計表')->getCell('A30')->getValue());
        $this->assertNull($book->getSheetByName('月計表')->getCell('A31')->getValue());
        $this->assertNull($book->getSheetByName('月計表')->getCell('Q30')->getValue());
        $this->assertNull($book->getSheetByName('稼働率（社員）')->getCell('B33')->getValue());
        $this->assertSame('ARK自由が丘店2026.02.xlsx', $export['filename']);
        $book->disconnectWorksheets();
    }

    public function test_original_layout_refuses_treatment_basis_that_cannot_match_payment_method_columns(): void
    {
        config()->set('report_export.template_file', 'ark-jiyugaoka-2026-10-source.xlsx');
        $this->expectException(\InvalidArgumentException::class);
        app(ReportWorkbookService::class)->build(2026, 10, SalesBasis::TreatmentDate, '2026-10-15');
    }

    public function test_exact_six_sheets_use_reporting_values_and_future_days_are_blank(): void
    {
        $method = PaymentMethod::factory()->create(['name' => '=HYPERLINK("bad")']);
        $this->checkout('2026-10-10', $method, 1100);
        $this->checkout('2026-10-20', $method, 2200);
        $export = app(ReportWorkbookService::class)->build(2026, 10, asOfDate: '2026-10-15');
        $book = $export['workbook'];
        $this->assertSame(ArkSixSheetCellMap::SHEETS, $book->getSheetNames());
        $this->assertSame('ARK_Report_2026-10_asof_2026-10-15.xlsx', $export['filename']);
        $this->assertSame(1100, $book->getSheetByName('数値')->getCell('B6')->getValue());
        $this->assertSame("'=HYPERLINK(\"bad\")", $book->getSheetByName('数値')->getCell('D6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $book->getSheetByName('数値')->getCell('D6')->getDataType());
        $this->assertSame(1, $book->getSheetByName('日報')->getCell('C14')->getValue());
        $this->assertNull($book->getSheetByName('日報')->getCell('C24')->getValue());
        $this->assertNull($book->getSheetByName('月計表')->getCell('C24')->getValue());
        $this->assertSame(1100, $book->getSheetByName('月計表')->getCell('C37')->getValue());
        $this->assertSame(1100, $book->getSheetByName('年間計画書（実数）')->getCell('B14')->getValue());
        $this->assertSame(1100, $book->getSheetByName('年間計画書（実数）')->getCell('B17')->getValue());
        $this->assertNull($book->getSheetByName('年間計画書（実数）')->getCell('B15')->getValue());
        $this->assertSame(100, $book->getSheetByName('数値')->getCell('I6')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $book->getSheetByName('数値')->getCell('B6')->getDataType());
        $book->disconnectWorksheets();
    }

    public function test_export_is_real_xlsx_and_month_lengths_keep_future_cells_blank(): void
    {
        foreach ([[2026, 2, 28], [2028, 2, 29], [2026, 4, 30], [2026, 10, 31]] as [$year, $month, $days]) {
            $export = app(ReportWorkbookService::class)->build($year, $month,
                asOfDate: sprintf('%04d-%02d-%02d', $year, $month, 15));
            $book = $export['workbook'];
            $lastRow = $days + 4;
            $this->assertSame(sprintf('%04d-%02d-%02d', $year, $month, $days),
                $book->getSheetByName('日報')->getCell('A'.$lastRow)->getValue());
            $this->assertNull($book->getSheetByName('日報')->getCell('C'.$lastRow)->getValue());
            $path = tempnam(sys_get_temp_dir(), 'ark-report-');
            try {
                (new Xlsx($book))->save($path);
                $loaded = IOFactory::load($path);
                $this->assertSame(ArkSixSheetCellMap::SHEETS, $loaded->getSheetNames());
                $loaded->disconnectWorksheets();
            } finally {
                $book->disconnectWorksheets();
                unlink($path);
            }
        }
    }

    public function test_unapproved_template_path_is_rejected_without_touching_any_original(): void
    {
        config()->set('report_export.template_file', '../legacy.xlsx');
        $this->expectException(RuntimeException::class);
        app(WorkbookTemplateRegistry::class)->load();
    }

    public function test_payment_method_and_tax_buckets_come_from_reporting_snapshots(): void
    {
        $cash = PaymentMethod::factory()->create(['name' => '現金']);
        $wallet = PaymentMethod::factory()->create(['name' => '電子決済']);
        $this->checkout('2026-10-10', $cash, 1100);
        $this->checkout('2026-10-11', $wallet, 1080, 800);
        $export = app(ReportWorkbookService::class)->build(2026, 10, asOfDate: '2026-10-15');
        $sheet = $export['workbook']->getSheetByName('数値');
        $this->assertSame(['現金', '電子決済'], [$sheet->getCell('D6')->getValue(), $sheet->getCell('D7')->getValue()]);
        $this->assertSame([1100, 1080], [$sheet->getCell('E6')->getValue(), $sheet->getCell('E7')->getValue()]);
        $this->assertSame([80, 100], [$sheet->getCell('I6')->getValue(), $sheet->getCell('I7')->getValue()]);
        $export['workbook']->disconnectWorksheets();
    }

    public function test_employee_and_part_time_sheets_use_employment_history_not_name_guessing(): void
    {
        $employee = EmploymentType::query()->create(['code' => 'employee', 'name' => '社員']);
        $part = EmploymentType::query()->create(['code' => 'part_time', 'name' => 'アルバイト']);
        foreach ([[$employee, 30], [$part, 45]] as [$type, $minutes]) {
            $staff = Staff::factory()->create();
            StaffEmploymentPeriod::query()->create(['staff_id' => $staff->user_id,
                'employment_type_id' => $type->id, 'effective_from' => '2026-10-01']);
            $visit = Visit::factory()->create(['business_date' => '2026-10-10', 'status' => 'completed',
                'primary_staff_id' => $staff->user_id]);
            $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id, 'status' => 'completed',
                'actual_minutes' => $minutes]);
            VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id,
                'staff_id' => $staff->user_id, 'actual_minutes' => $minutes]);
        }
        $export = app(ReportWorkbookService::class)->build(2026, 10, asOfDate: '2026-10-15');
        $this->assertSame(30, $export['workbook']->getSheetByName('稼働率（社員）')->getCell('C14')->getValue());
        $this->assertSame(45, $export['workbook']->getSheetByName('稼働率（アルバイト）')->getCell('C14')->getValue());
        $export['workbook']->disconnectWorksheets();
    }

    private function checkout(string $date, PaymentMethod $method, int $gross, int $taxRateBps = 1000): void
    {
        $tax = intdiv($gross * $taxRateBps, 10_000 + $taxRateBps);
        $visit = Visit::factory()->create(['business_date' => $date, 'status' => 'completed',
            'visit_sequence' => 1, 'future_reservation_exists_at_checkout' => false]);
        $checkout = Checkout::factory()->create(['visit_id' => $visit->id, 'status' => CheckoutStatus::Draft,
            'subtotal_amount' => $gross - $tax, 'tax_amount' => $tax, 'total_amount' => $gross]);
        CheckoutLine::factory()->create(['checkout_id' => $checkout->id, 'unit_amount' => $gross,
            'net_amount' => $gross - $tax, 'tax_amount' => $tax, 'gross_amount' => $gross,
            'tax_category_code_snapshot' => 'rate_'.$taxRateBps, 'tax_category_name_snapshot' => '税率'.$taxRateBps,
            'tax_rate_bps' => $taxRateBps]);
        CheckoutTender::factory()->create(['checkout_id' => $checkout->id, 'payment_method_id' => $method->id,
            'amount' => $gross, 'status' => CheckoutTenderStatus::Received,
            'received_at' => $date.' 01:00:00']);
        $checkout->update(['status' => CheckoutStatus::Finalized, 'finalized_at' => $date.' 01:00:00']);
    }
}
