<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\Excel\ReportWorkbookService;
use App\Enums\Reporting\SalesBasis;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportWorkbookController extends Controller
{
    public function __invoke(Request $request, ReportWorkbookService $exports, AuditLogger $audit, BusinessTime $time): StreamedResponse
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
            sprintf('6シート帳票 %s / %s', $export['filename'], config('report_export.template_version')), $request->user());

        return response()->streamDownload(function () use ($export): void {
            try {
                (new Xlsx($export['workbook']))->save('php://output');
            } finally {
                $export['workbook']->disconnectWorksheets();
            }
        }, $export['filename'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
