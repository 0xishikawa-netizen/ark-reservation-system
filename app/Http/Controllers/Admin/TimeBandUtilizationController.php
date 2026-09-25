<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\TimeBandUtilizationService;
use App\Http\Controllers\Controller;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class TimeBandUtilizationController extends Controller
{
    public function index(Request $request, TimeBandUtilizationService $reports, BusinessTime $time): Response
    {
        return Inertia::render('Admin/Reports/TimeBands', [
            'report' => $this->report($request, $reports, $time),
            'dataEndpoint' => route('admin.reports.time-bands.data'),
        ]);
    }

    public function data(Request $request, TimeBandUtilizationService $reports, BusinessTime $time): JsonResponse
    {
        return response()->json(['data' => $this->report($request, $reports, $time)]);
    }

    /** @return array<string,mixed> */
    private function report(Request $request, TimeBandUtilizationService $reports, BusinessTime $time): array
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $today = $time->businessDate();
        try {
            return $reports->forMonth((int) ($input['year'] ?? $today->year), (int) ($input['month'] ?? $today->month),
                isset($input['staff_id']) ? (int) $input['staff_id'] : null, $input['as_of_date'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }
    }
}
