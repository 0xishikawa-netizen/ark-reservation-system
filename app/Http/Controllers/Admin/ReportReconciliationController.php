<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\ReportReconciliationService;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ReportReconciliationController extends Controller
{
    public function index(Request $request, ReportReconciliationService $reports, BusinessTime $time): JsonResponse
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'batch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $today = $time->businessDate();
        try {
            $report = $reports->forMonth((int) ($input['year'] ?? $today->year),
                (int) ($input['month'] ?? $today->month), isset($input['batch_id']) ? (int) $input['batch_id'] : null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['batch_id' => $e->getMessage()]);
        }

        return response()->json(['data' => $report]);
    }

    public function review(int $historicalMetric, Request $request, ReportReconciliationService $reports, AuditLogger $audit): JsonResponse
    {
        $input = $request->validate([
            'difference_category' => ['required', Rule::in(ReportReconciliationService::DIFFERENCE_CATEGORIES)],
            'review_status' => ['required', Rule::in(['confirmed', 'needs_attention'])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        try {
            $review = $reports->review($historicalMetric, $input['difference_category'],
                $input['review_status'], $input['reason'], (int) $request->user()->getAuthIdentifier());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['historical_metric' => $e->getMessage()]);
        }
        $audit->log('reports.reconciliation_reviewed', null, 'historical metric '.$historicalMetric, $request->user());

        return response()->json(['data' => $review]);
    }
}
