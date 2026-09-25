<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Excel;

use App\Domain\Reporting\AnnualReportService;
use App\Domain\Reporting\CustomerAnalyticsService;
use App\Domain\Reporting\MonthlyReportService;
use App\Domain\Reporting\StaffUtilizationService;
use App\Enums\Reporting\SalesBasis;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

final class ReportWorkbookService
{
    public function __construct(
        private readonly MonthlyReportService $monthly,
        private readonly CustomerAnalyticsService $customers,
        private readonly AnnualReportService $annual,
        private readonly StaffUtilizationService $staff,
        private readonly ArkSixSheetCellMap $mapping,
        private readonly LegacySixSheetCellMap $legacyMapping,
        private readonly WorkbookTemplateRegistry $templates,
    ) {}

    /** @return array{workbook:Spreadsheet,filename:string,as_of_date:string} */
    public function build(int $year, int $month, SalesBasis|string $basis = SalesBasis::PaymentDate, ?string $asOfDate = null): array
    {
        if (config('report_export.template_file') !== null
            && ($basis instanceof SalesBasis ? $basis : SalesBasis::tryFrom($basis)) !== SalesBasis::PaymentDate) {
            throw new \InvalidArgumentException('原本互換帳票は決済日基準のみ対応します。施術日基準は管理画面の集計を使用してください。');
        }
        $monthReport = $this->monthly->forMonth($year, $month, $basis, $asOfDate);
        $asOf = $monthReport->asOfDate;
        $customers = $this->customers->forMonth($year, $month, $asOf);
        $annual = $this->annual->forYear($year, $basis, $asOf);
        $staff = $this->staff->forMonth($year, $month, asOfDate: $asOf);
        $book = $this->templates->load();
        $provisional = config('report_export.template_file') === null;
        if ($provisional) {
            $cells = $this->mapping->cells($monthReport, $customers, $annual, $staff);
        } else {
            $fiscalStart = $month >= 4 ? $year : $year - 1;
            $fiscalAnnual = [$year => $annual];
            foreach ([$fiscalStart, $fiscalStart + 1] as $fiscalYear) {
                if (! isset($fiscalAnnual[$fiscalYear])) {
                    $fiscalAsOf = $asOf < sprintf('%04d-01-01', $fiscalYear)
                        ? sprintf('%04d-12-31', $fiscalYear - 1)
                        : ($asOf > sprintf('%04d-12-31', $fiscalYear) ? sprintf('%04d-12-31', $fiscalYear) : $asOf);
                    $fiscalAnnual[$fiscalYear] = $this->annual->forYear($fiscalYear, $basis, $fiscalAsOf);
                }
            }
            $cells = $this->legacyMapping->cells($monthReport, $fiscalAnnual, $staff);
            // 原本の数式には税率の固定値や0除算の隠蔽がある。出力コピーでは数式を一切正本にしない。
            foreach ($book->getAllSheets() as $sheet) {
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    if ($sheet->getCell($coordinate)->isFormula()) {
                        $sheet->setCellValue($coordinate, null);
                    }
                }
            }
        }
        foreach ($cells as $sheetName => $mapped) {
            $sheet = $book->getSheetByName($sheetName);
            foreach ($mapped as $coordinate => $entry) {
                $value = $entry['value'];
                $kind = $entry['kind'];
                if ($value === null) {
                    $sheet->setCellValue($coordinate, null);
                } elseif ($kind === 'text') {
                    $sheet->setCellValueExplicit($coordinate, $this->safeText((string) $value), DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
                    if ($provisional
                        || ($kind === 'rate' && $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode() === NumberFormat::FORMAT_GENERAL)
                        || ($sheetName === '年間計画書 (実数)' && preg_match('/^[A-Z]+6$/', $coordinate) === 1)) {
                        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(
                            $kind === 'rate' ? NumberFormat::FORMAT_PERCENTAGE_00 : '#,##0',
                        );
                    }
                }
            }
            if ($provisional) {
                $sheet->freezePane('A5');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
                $sheet->getStyle('A4:Q4')->getFont()->setBold(true);
                $sheet->getStyle('A4:Q4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCEAF2');
                $sheet->getColumnDimension('A')->setWidth(23);
                foreach (range('B', 'Q') as $column) {
                    $sheet->getColumnDimension($column)->setWidth(18);
                }
            }
        }
        $book->setActiveSheetIndex(0);

        return ['workbook' => $book,
            'filename' => $provisional
                ? sprintf('ARK_Report_%04d-%02d_asof_%s.xlsx', $year, $month, $asOf)
                : sprintf((string) config('report_export.download_filename_pattern'), $year, $month),
            'as_of_date' => $asOf];
    }

    private function safeText(string $value): string
    {
        // Excel/CSVで式と解釈されるプレフィックスを文字列として固定する。
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
