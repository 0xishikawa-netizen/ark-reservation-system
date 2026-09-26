<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\Excel\CanonicalTemplateXlsxWriter;
use App\Domain\Reporting\Excel\ReportWorkbookService;
use App\Enums\Reporting\SalesBasis;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportWorkbookController extends Controller
{
    public function __invoke(Request $request, ReportWorkbookService $exports, CanonicalTemplateXlsxWriter $writer, AuditLogger $audit, BusinessTime $time): StreamedResponse
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'basis' => ['nullable', Rule::enum(SalesBasis::class)],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $today = $time->businessDate();
        try {
            $export = $exports->build((int) ($input['year'] ?? $today->year),
                (int) ($input['month'] ?? $today->month),
                (string) ($input['basis'] ?? SalesBasis::PaymentDate->value), $input['as_of_date'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }
        $audit->log('reports.workbook_exported', null,
            sprintf('6シート帳票 %s / %s / 未対応項目 %d件', $export['filename'], config('report_export.template_version'), count($export['warnings'])), $request->user());

        $response = response()->streamDownload(function () use ($export, $writer): void {
            $temporary = tempnam(sys_get_temp_dir(), 'ark-report-output-');
            if ($temporary === false) {
                throw new RuntimeException('帳票の一時出力先を作成できません。');
            }
            try {
                $writer->save($export['cell_values'], $temporary);
                readfile($temporary);
            } finally {
                unlink($temporary);
                $export['workbook']->disconnectWorksheets();
            }
        }, $export['filename'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        $response->headers->set('X-ARK-Export-Warning-Count', (string) count($export['warnings']));
        $response->headers->set('X-ARK-Export-Warnings-Base64', base64_encode(json_encode(array_slice($export['warnings'], 0, 20), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));

        return $response;
    }
}
