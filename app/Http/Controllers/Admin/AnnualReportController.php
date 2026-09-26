<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\AnnualReportService;
use App\Enums\Reporting\SalesBasis;
use App\Http\Controllers\Controller;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class AnnualReportController extends Controller
{
    public function index(Request $request, AnnualReportService $reports, BusinessTime $time): Response
    {
        return Inertia::render('Admin/Reports/Annual', [
            'report' => $this->report($request, $reports, $time),
            'dataEndpoint' => route('admin.reports.annual.data'),
        ]);
    }

    public function data(Request $request, AnnualReportService $reports, BusinessTime $time): JsonResponse
    {
        return response()->json(['data' => $this->report($request, $reports, $time)]);
    }

    /** @return array<string,mixed> */
    private function report(Request $request, AnnualReportService $reports, BusinessTime $time): array
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'basis' => ['nullable', Rule::enum(SalesBasis::class)],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', Rule::in([AnnualReportService::PERIOD_FISCAL, AnnualReportService::PERIOD_CALENDAR])],
        ]);
        // 既定は事業年度（4月〜翌3月）。今日が1〜3月なら前年度（Task 11-24）。
        $period = (string) ($input['period'] ?? AnnualReportService::PERIOD_FISCAL);
        $today = $time->businessDate();
        $defaultYear = $period === AnnualReportService::PERIOD_FISCAL && $today->month < AnnualReportService::FISCAL_START_MONTH
            ? $today->year - 1 : $today->year;
        try {
            return $reports->forYear((int) ($input['year'] ?? $defaultYear),
                (string) ($input['basis'] ?? SalesBasis::PaymentDate->value), $input['as_of_date'] ?? null, $period);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['year' => $e->getMessage()]);
        }
    }
}
