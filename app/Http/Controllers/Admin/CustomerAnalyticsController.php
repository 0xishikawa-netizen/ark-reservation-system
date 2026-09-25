<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\CustomerAnalyticsService;
use App\Domain\Reporting\MonthlyCustomerSummary;
use App\Http\Controllers\Controller;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class CustomerAnalyticsController extends Controller
{
    public function index(Request $request, CustomerAnalyticsService $analytics, BusinessTime $businessTime): Response
    {
        return Inertia::render('Admin/Reports/Customers', [
            'report' => $this->report($request, $analytics, $businessTime),
            'dataEndpoint' => route('admin.reports.customers.data'),
            'monthlyReportUrl' => route('admin.reports.monthly'),
        ]);
    }

    public function data(Request $request, CustomerAnalyticsService $analytics, BusinessTime $businessTime): JsonResponse
    {
        return response()->json(['data' => $this->report($request, $analytics, $businessTime)]);
    }

    private function report(Request $request, CustomerAnalyticsService $analytics, BusinessTime $businessTime): MonthlyCustomerSummary
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $today = $businessTime->businessDate();

        try {
            return $analytics->forMonth(
                (int) ($validated['year'] ?? $today->year),
                (int) ($validated['month'] ?? $today->month),
                $validated['as_of_date'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['as_of_date' => $exception->getMessage()]);
        }
    }
}
