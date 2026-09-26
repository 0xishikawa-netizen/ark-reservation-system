<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\StaffSalesService;
use App\Http\Controllers\Controller;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** スタッフ別売上・指名売上（Task 11-22）。 */
final class StaffSalesController extends Controller
{
    public function index(Request $request, StaffSalesService $reports, BusinessTime $time): Response
    {
        return Inertia::render('Admin/Reports/StaffSales', [
            'report' => $this->report($request, $reports, $time),
            'dataEndpoint' => route('admin.reports.staff-sales.data'),
        ]);
    }

    public function data(Request $request, StaffSalesService $reports, BusinessTime $time): JsonResponse
    {
        return response()->json(['data' => $this->report($request, $reports, $time)]);
    }

    /** @return array<string, mixed> */
    private function report(Request $request, StaffSalesService $reports, BusinessTime $time): array
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'basis' => ['nullable', Rule::in(['payment_date', 'treatment_date'])],
        ]);
        $today = $time->businessDate();
        try {
            return $reports->forMonth((int) ($input['year'] ?? $today->year), (int) ($input['month'] ?? $today->month), $input['basis'] ?? 'payment_date');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }
    }
}
