<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Excel\WorkbookTemplateRegistry;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** 提供Excel原本コピーの観測可能な入力値だけを確認し、数式キャッシュを実績と誤認しない。 */
final class ProvidedWorkbookEvidence
{
    public function __construct(private readonly WorkbookTemplateRegistry $templates) {}

    /** @return array<string,mixed>|null */
    public function forPeriod(int $year, int $month): ?array
    {
        if (config('report_export.template_version') !== 'ark-jiyugaoka-2026-10-v1'
            || config('report_export.source_period') !== sprintf('%04d-%02d', $year, $month)
            || config('report_export.template_file') === null) {
            return null;
        }
        $book = $this->templates->load();
        try {
            $numbers = $book->getSheetByName('数値');
            if ((int) $numbers->getCell('A3')->getValue() !== $year
                || (int) $numbers->getCell('B3')->getValue() !== $month) {
                return null;
            }

            $monthly = $book->getSheetByName('月計表');
            $saleAndVisitColumns = ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'K', 'L', 'M', 'N', 'O',
                'R', 'S', 'T', 'V', 'W', 'Y', 'Z', 'AA', 'AB', 'AC'];
            $monthlyInputs = $this->countObservedCells($monthly, 3, 33, $saleAndVisitColumns);
            $staffColumns = ['C', 'D', 'E', 'F', 'G', 'K', 'L', 'M', 'N', 'O',
                'S', 'T', 'U', 'V', 'W', 'AA', 'AB', 'AC', 'AD', 'AE'];
            $employeeInputs = $this->countObservedCells($book->getSheetByName('稼働率（社員）'), 5, 35, $staffColumns);
            $partTimeInputs = $this->countObservedCells($book->getSheetByName('稼働率（アルバイト）'), 5, 35, $staffColumns);
            $actualInputCount = $monthlyInputs + $employeeInputs + $partTimeInputs;
            $targets = [
                '数値!C6' => $numbers->getCell('C6')->getValue(),
                '月計表!C36' => $monthly->getCell('C36')->getValue(),
                '年間計画書 (実数)!O4' => $book->getSheetByName('年間計画書 (実数)')->getCell('O4')->getValue(),
            ];

            return [
                'source_filename' => 'ARK自由が丘店2026.10.xlsx',
                'sha256' => config('report_export.template_sha256'),
                'period' => sprintf('%04d-%02d', $year, $month),
                'sheet_names' => $book->getSheetNames(),
                'structure_verified' => true,
                'source_actual_input_count' => $actualInputCount,
                'monthly_actual_input_count' => $monthlyInputs,
                'staff_actual_input_count' => $employeeInputs + $partTimeInputs,
                'cached_formula_zero_is_actual' => false,
                'target_candidates' => $targets,
                'target_conflict' => count(array_unique(array_values($targets))) > 1,
                'numeric_reconciliation_status' => $actualInputCount === 0 ? 'blocked_no_actual_source_values' : 'source_values_available',
                'google_sheets_status' => 'not_provided',
            ];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @param list<string> $columns */
    private function countObservedCells(Worksheet $sheet, int $firstRow, int $lastRow, array $columns): int
    {
        $count = 0;
        foreach (range($firstRow, $lastRow) as $row) {
            foreach ($columns as $column) {
                $cell = $sheet->getCell($column.$row);
                $value = $cell->getValue();
                if (! $cell->isFormula() && $value !== null && (! is_string($value) || trim($value) !== '')) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
