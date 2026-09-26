<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use App\Domain\Reporting\DailyBusinessNoteService;
use App\Domain\Reporting\MonthlyReportService;
use App\Domain\Reporting\StaffUtilizationService;
use App\Enums\Reporting\SalesBasis;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class ReportWorkbookService
{
    public function __construct(
        private readonly MonthlyReportService $monthly,
        private readonly StaffUtilizationService $staff,
        private readonly DailyBusinessNoteService $notes,
        private readonly LegacySixSheetCellMap $mapping,
        private readonly WorkbookTemplateRegistry $templates,
    ) {}

    /** @return array{workbook:Spreadsheet,filename:string,as_of_date:string,warnings:list<string>,mapped_cells:array<string,list<string>>,cell_values:array<string,array<string,array{value:string|int|float|null,kind:string}>>} */
    public function build(int $year, int $month, SalesBasis|string $basis = SalesBasis::PaymentDate, ?string $asOfDate = null): array
    {
        if (($basis instanceof SalesBasis ? $basis : SalesBasis::tryFrom($basis)) !== SalesBasis::PaymentDate) {
            throw new \InvalidArgumentException('原本互換帳票は決済日基準のみ対応します。施術日基準は管理画面の集計を使用してください。');
        }
        $monthReport = $this->monthly->forMonth($year, $month, $basis, $asOfDate);
        $asOf = $monthReport->asOfDate;
        $staff = $this->staff->forMonth($year, $month, asOfDate: $asOf);
        $book = $this->templates->load();
        $cells = $this->mapping->cells($monthReport, $staff, $this->notes->forMonth($year, $month));
        $warnings = $this->mapping->warnings($monthReport, $staff);
        if ($year !== 2026 && ! ($year === 2027 && $month <= 3)) {
            $warnings[] = 'annual plan belongs to original fiscal year 2026; ARK annual actuals are available in admin reporting';
        }
        foreach ($cells as $sheetName => $mapped) {
            $sheet = $book->getSheetByName($sheetName);
            foreach ($mapped as $coordinate => $entry) {
                $value = $entry['value'];
                $kind = $entry['kind'];
                if ($value === null) {
                    $sheet->setCellValue($coordinate, null);
                } elseif ($kind === 'text') {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
                }
            }
        }

        return [
            'workbook' => $book,
            'filename' => sprintf((string) config('report_export.download_filename_pattern'), $year, $month),
            'as_of_date' => $asOf,
            'warnings' => $warnings,
            'mapped_cells' => array_map(static fn (array $sheetCells): array => array_keys($sheetCells), $cells),
            'cell_values' => $cells,
        ];
    }
}
