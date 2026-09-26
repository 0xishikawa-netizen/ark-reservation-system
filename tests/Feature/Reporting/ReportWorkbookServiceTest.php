<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Excel\CanonicalTemplateXlsxWriter;
use App\Domain\Reporting\Excel\LegacySixSheetCellMap;
use App\Domain\Reporting\Excel\ReportWorkbookService;
use App\Domain\Reporting\Excel\WorkbookTemplateRegistry;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Enums\Reporting\SalesBasis;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\DailyBusinessNote;
use App\Models\PaymentMethod;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class ReportWorkbookServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'ark-jiyugaoka-2026-10-source.xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('report_export.template_file', self::TEMPLATE);
    }

    public function test_original_copy_keeps_six_sheet_structure_headers_formulas_and_narratives(): void
    {
        $sourcePath = resource_path('report_templates/'.self::TEMPLATE);
        $sourceHash = hash_file('sha256', $sourcePath);
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金']);
        $this->checkout('2026-09-10', $cash, 1100);
        $this->checkout('2026-09-20', $cash, 2200);
        DailyBusinessNote::query()->create([
            'business_date' => '2026-09-10',
            'business_condition' => "午前は静か\n夕方に集中",
            'reflection' => "案内を改善\n明日確認",
        ]);
        DailyBusinessNote::query()->create([
            'business_date' => '2026-09-20',
            'business_condition' => '未来日の記録',
        ]);
        DailyBusinessNote::query()->create([
            'business_date' => '2026-09-11',
            'reflection' => '=1+1',
        ]);

        $export = app(ReportWorkbookService::class)->build(2026, 9, asOfDate: '2026-09-15');
        $book = $export['workbook'];
        $this->assertSame(LegacySixSheetCellMap::SHEETS, $book->getSheetNames());
        $this->assertSame('ARK自由が丘店2026.09.xlsx', $export['filename']);
        $month = $book->getSheetByName('月計表');
        $this->assertSame($this->templateHeaders(), $this->headers($month));
        $this->assertSame(1100, $month->getCell('C12')->getValue());
        $this->assertSame(1100, $month->getCell('J12')->getValue());
        $this->assertSame(1100, $month->getCell('Q12')->getValue());
        $this->assertNull($month->getCell('Q22')->getValue());
        $this->assertNull($month->getCell('A33')->getValue());
        $this->assertSame('=SUM(K12:M12)/1.08', $month->getCell('P12')->getValue());
        $this->assertNull($month->getCell('P22')->getValue());
        $this->assertSame("午前は静か\n夕方に集中", $book->getSheetByName('日報')->getCell('D12')->getValue());
        $this->assertSame("案内を改善\n明日確認", $book->getSheetByName('日報')->getCell('H12')->getValue());
        $this->assertNull($book->getSheetByName('日報')->getCell('D22')->getValue());
        $this->assertSame('=1+1', $book->getSheetByName('日報')->getCell('H13')->getValue());
        $this->assertFalse($book->getSheetByName('日報')->getCell('H13')->isFormula());
        $this->assertNull($book->getSheetByName('日報')->getCell('B33')->getValue());
        $this->assertSame(1100, $book->getSheetByName('数値')->getCell('K13')->getValue());
        $this->assertNull($book->getSheetByName('数値')->getCell('K23')->getValue());
        $this->assertSame('=IF(K4=0,"",K4-H4)', $book->getSheetByName('数値')->getCell('L4')->getValue());
        $this->assertSame('社内売上', $book->getSheetByName('年間計画書 (実数)')->getCell('A5')->getValue());
        $this->assertSame('売上合計', $book->getSheetByName('年間計画書 (実数)')->getCell('A6')->getValue());

        $saved = tempnam(sys_get_temp_dir(), 'ark-report-');
        try {
            app(CanonicalTemplateXlsxWriter::class)->save($export['cell_values'], $saved);
            $this->assertUnmappedArchivePartsUnchanged($sourcePath, $saved);
            $reloaded = IOFactory::load($saved);
            $original = IOFactory::load($sourcePath);
            $this->assertCanonicalStructure($original, $reloaded, $export['mapped_cells']);
            $this->assertSame($this->templateHeaders(), $this->headers($reloaded->getSheetByName('月計表')));
            foreach (['A4' => '売上', 'A5' => '社内売上', 'A6' => '売上合計', 'A8' => '売上原価',
                'A12' => '売上総利益', 'A14' => '人件費', 'A19' => '管理可能　　経費',
                'A28' => '管理不可能経費', 'A40' => '営業利益', 'A42' => '本部利益',
                'A45' => '賞与原資'] as $coordinate => $label) {
                $this->assertSame($label, $reloaded->getSheetByName('年間計画書 (実数)')->getCell($coordinate)->getValue());
            }
            $savedNarrative = $reloaded->getSheetByName('日報')->getCell('D12')->getValue();
            $this->assertSame("午前は静か\n夕方に集中", $savedNarrative instanceof RichText
                ? $savedNarrative->getPlainText() : $savedNarrative);
            $formulaLikeNarrative = $reloaded->getSheetByName('日報')->getCell('H13')->getValue();
            $this->assertSame('=1+1', $formulaLikeNarrative instanceof RichText
                ? $formulaLikeNarrative->getPlainText() : $formulaLikeNarrative);
            $this->assertFalse($reloaded->getSheetByName('日報')->getCell('H13')->isFormula());
            $original->disconnectWorksheets();
            $reloaded->disconnectWorksheets();
        } finally {
            unlink($saved);
            $book->disconnectWorksheets();
        }
        $this->assertSame($sourceHash, hash_file('sha256', $sourcePath));
    }

    public function test_30_31_and_february_months_keep_all_template_rows_and_blank_nonexistent_days(): void
    {
        foreach ([[2026, 2, 28], [2028, 2, 29], [2026, 9, 30], [2026, 10, 31]] as [$year, $month, $lastDay]) {
            $export = app(ReportWorkbookService::class)->build($year, $month,
                asOfDate: sprintf('%04d-%02d-15', $year, $month));
            $book = $export['workbook'];
            $this->assertSame(1000, $book->getSheetByName('月計表')->getHighestRow());
            $this->assertSame('AD', $book->getSheetByName('月計表')->getHighestColumn());
            $this->assertSame($lastDay, $book->getSheetByName('月計表')->getCell('A'.($lastDay + 2))->getValue());
            if ($lastDay < 31) {
                $this->assertNull($book->getSheetByName('月計表')->getCell('A'.($lastDay + 3))->getValue());
                $this->assertNull($book->getSheetByName('日報')->getCell('B'.($lastDay + 3))->getValue());
            }
            $this->assertSame($this->templateHeaders(), $this->headers($book->getSheetByName('月計表')));
            $book->disconnectWorksheets();
        }
    }

    public function test_unmapped_master_values_warn_without_changing_fixed_headers_or_adding_columns(): void
    {
        $stripe = PaymentMethod::factory()->create(['code' => 'stripe', 'name' => 'PHASE11_TEST_決済']);
        $this->checkout('2026-09-10', $stripe, 1100);
        $export = app(ReportWorkbookService::class)->build(2026, 9, asOfDate: '2026-09-15');
        $book = $export['workbook'];
        $this->assertSame($this->templateHeaders(), $this->headers($book->getSheetByName('月計表')));
        $this->assertSame('AD', $book->getSheetByName('月計表')->getHighestColumn());
        $this->assertStringNotContainsString('PHASE11_TEST_', implode('|', $this->headers($book->getSheetByName('月計表'))));
        $this->assertContains('unmapped payment method: stripe (1100円)', $export['warnings']);
        $this->assertContains('unmapped tax category: rate_1000 / 1000 bps', $export['warnings']);
        $this->assertSame(1100, $book->getSheetByName('月計表')->getCell('Q12')->getValue());
        $this->assertSame(0, $book->getSheetByName('月計表')->getCell('J12')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_template_is_required_and_treatment_basis_is_rejected(): void
    {
        config()->set('report_export.template_file', null);
        $this->expectException(RuntimeException::class);
        app(WorkbookTemplateRegistry::class)->load();
    }

    public function test_treatment_date_basis_is_rejected_for_legacy_columns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ReportWorkbookService::class)->build(2026, 9, SalesBasis::TreatmentDate, '2026-09-15');
    }

    /** @return list<string|null> */
    private function templateHeaders(): array
    {
        $original = IOFactory::load(resource_path('report_templates/'.self::TEMPLATE));
        $headers = $this->headers($original->getSheetByName('月計表'));
        $original->disconnectWorksheets();

        return $headers;
    }

    /** @return list<string|null> */
    private function headers(Worksheet $sheet): array
    {
        $headers = [];
        for ($column = 1; $column <= 29; $column++) {
            $headers[] = $sheet->getCell([$column, 2])->getValue();
        }

        return $headers;
    }

    /** @param array<string,list<string>> $mapped */
    private function assertCanonicalStructure(Spreadsheet $original, Spreadsheet $output, array $mapped): void
    {
        $this->assertSame($original->getSheetNames(), $output->getSheetNames());
        $this->assertSame(array_keys($original->getNamedRanges()), array_keys($output->getNamedRanges()), 'defined names');
        foreach ($original->getSheetNames() as $name) {
            $source = $original->getSheetByName($name);
            $target = $output->getSheetByName($name);
            $this->assertSame($source->getHighestRow(), $target->getHighestRow(), $name.' rows');
            $this->assertSame($source->getHighestColumn(), $target->getHighestColumn(), $name.' columns');
            $this->assertSame(array_keys($source->getMergeCells()), array_keys($target->getMergeCells()), $name.' merges');
            $this->assertSame($source->getFreezePane(), $target->getFreezePane(), $name.' freeze');
            $this->assertSame($source->getPageSetup()->getOrientation(), $target->getPageSetup()->getOrientation(), $name.' orientation');
            $this->assertSame($source->getPageSetup()->getPaperSize(), $target->getPageSetup()->getPaperSize(), $name.' paper');
            $this->assertSame($source->getPageSetup()->getPrintArea(), $target->getPageSetup()->getPrintArea(), $name.' print area');
            $this->assertSame(array_keys($source->getConditionalStylesCollection()), array_keys($target->getConditionalStylesCollection()), $name.' conditional formatting');
            $this->assertSame(array_keys($source->getDataValidationCollection()), array_keys($target->getDataValidationCollection()), $name.' validations');
            $this->assertSame(array_keys($source->getComments()), array_keys($target->getComments()), $name.' comments');
            foreach ($source->getRowDimensions() as $row => $dimension) {
                $this->assertSame($dimension->getRowHeight(), $target->getRowDimension($row)->getRowHeight(), $name.' row '.$row);
                $this->assertSame($dimension->getVisible(), $target->getRowDimension($row)->getVisible(), $name.' hidden row '.$row);
            }
            foreach ($source->getColumnDimensions() as $column => $dimension) {
                $this->assertSame($dimension->getWidth(), $target->getColumnDimension($column)->getWidth(), $name.' column '.$column);
                $this->assertSame($dimension->getVisible(), $target->getColumnDimension($column)->getVisible(), $name.' hidden column '.$column);
            }
            $owned = array_fill_keys($mapped[$name], true);
            $sourceCells = $source->getCellCollection()->getCoordinates();
            foreach ($sourceCells as $coordinate) {
                $sourceCell = $source->getCell($coordinate);
                $targetCell = $target->getCell($coordinate);
                $this->assertSame($sourceCell->getStyle()->getHashCode(), $targetCell->getStyle()->getHashCode(), $name.' '.$coordinate.' style');
                if (! isset($owned[$coordinate]) && $sourceCell->isFormula()) {
                    $this->assertSame($sourceCell->getValue(), $targetCell->getValue(), $name.' '.$coordinate.' formula');
                }
            }
        }
    }

    private function assertUnmappedArchivePartsUnchanged(string $sourcePath, string $outputPath): void
    {
        $source = new ZipArchive;
        $output = new ZipArchive;
        $this->assertTrue($source->open($sourcePath));
        $this->assertTrue($output->open($outputPath));
        try {
            $this->assertSame($source->numFiles, $output->numFiles);
            for ($index = 0; $index < $source->numFiles; $index++) {
                $part = $source->getNameIndex($index);
                $this->assertNotFalse($part);
                if ($part === 'xl/workbook.xml' || preg_match('#^xl/worksheets/sheet[1-6]\.xml$#', $part)) {
                    continue;
                }
                $this->assertSame($source->getFromName($part), $output->getFromName($part), $part);
            }
        } finally {
            $source->close();
            $output->close();
        }
    }

    private function checkout(string $date, PaymentMethod $method, int $gross): void
    {
        $tax = intdiv($gross * 1000, 11000);
        $visit = Visit::factory()->create(['business_date' => $date, 'status' => 'completed',
            'visit_sequence' => 1, 'future_reservation_exists_at_checkout' => false]);
        $checkout = Checkout::factory()->create(['visit_id' => $visit->id, 'status' => CheckoutStatus::Draft,
            'subtotal_amount' => $gross - $tax, 'tax_amount' => $tax, 'total_amount' => $gross]);
        CheckoutLine::factory()->create(['checkout_id' => $checkout->id, 'unit_amount' => $gross,
            'net_amount' => $gross - $tax, 'tax_amount' => $tax, 'gross_amount' => $gross,
            'tax_category_code_snapshot' => 'rate_1000', 'tax_category_name_snapshot' => '10%税', 'tax_rate_bps' => 1000]);
        CheckoutTender::factory()->create(['checkout_id' => $checkout->id, 'payment_method_id' => $method->id,
            'amount' => $gross, 'status' => CheckoutTenderStatus::Received,
            'received_at' => $date.' 01:00:00']);
        $checkout->update(['status' => CheckoutStatus::Finalized, 'finalized_at' => $date.' 01:00:00']);
    }
}
