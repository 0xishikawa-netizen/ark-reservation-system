<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\MonthlyBusinessSummary;
use App\Domain\Reporting\MonthlyReportService;
use App\Enums\Reporting\SalesBasis;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class MonthlyReportController extends Controller
{
    public function index(Request $request, MonthlyReportService $reports, BusinessTime $businessTime): Response
    {
        return Inertia::render('Admin/Reports/Monthly', [
            'report' => $this->report($request, $reports, $businessTime),
            'dataEndpoint' => route('admin.reports.monthly.data'),
            'exportEndpoint' => $request->user()?->can('reports.export') ? route('admin.reports.excel') : null,
            // 日別実績の決済列を原本「月計表」と同じ並び（マスタ表示順）で固定表示するための列定義。
            // 金額の集計は report 側の既存値だけを使い、ここでは列の並びと名称だけを渡す。
            'paymentMethodColumns' => PaymentMethod::query()
                ->where('is_enabled', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get(['id', 'code', 'name'])
                ->map(static fn (PaymentMethod $method): array => [
                    'payment_method_id' => (int) $method->id,
                    'code' => (string) $method->code,
                    'name' => (string) $method->name,
                ])
                ->all(),
        ]);
    }

    public function data(Request $request, MonthlyReportService $reports, BusinessTime $businessTime): JsonResponse
    {
        return response()->json(['data' => $this->report($request, $reports, $businessTime)]);
    }

    private function report(Request $request, MonthlyReportService $reports, BusinessTime $businessTime): MonthlyBusinessSummary
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'basis' => ['nullable', Rule::enum(SalesBasis::class)],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $today = $businessTime->businessDate();

        try {
            return $reports->forMonth(
                (int) ($validated['year'] ?? $today->year),
                (int) ($validated['month'] ?? $today->month),
                (string) ($validated['basis'] ?? SalesBasis::PaymentDate->value),
                isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['month' => $exception->getMessage()]);
        }
    }
}
