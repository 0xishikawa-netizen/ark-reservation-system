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
        ]);
        try {
            return $reports->forYear((int) ($input['year'] ?? $time->businessDate()->year),
                (string) ($input['basis'] ?? SalesBasis::PaymentDate->value), $input['as_of_date'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['year' => $e->getMessage()]);
        }
    }
}
