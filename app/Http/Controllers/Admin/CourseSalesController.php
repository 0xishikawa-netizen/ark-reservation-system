<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\CourseSalesService;
use App\Http\Controllers\Controller;
use App\Models\CourseSalesTarget;
use App\Models\MembershipPlan;
use App\Models\Service;
use App\Models\TicketProduct;
use App\Support\Business\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** コース別売上・利用・目標（Task 11-25）。 */
final class CourseSalesController extends Controller
{
    public function index(Request $request, CourseSalesService $reports, BusinessTime $time): Response
    {
        return Inertia::render('Admin/Reports/CourseSales', [
            'report' => $this->report($request, $reports, $time),
            'dataEndpoint' => route('admin.reports.course-sales.data'),
            'targetEndpoint' => route('admin.reports.course-sales.targets'),
            'canEditTargets' => $request->user()?->can('settings.manage') ?? false,
        ]);
    }

    public function data(Request $request, CourseSalesService $reports, BusinessTime $time): JsonResponse
    {
        return response()->json(['data' => $this->report($request, $reports, $time)]);
    }

    public function saveTarget(Request $request, CourseSalesService $reports): RedirectResponse
    {
        $input = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'course_type' => ['required', Rule::in(CourseSalesTarget::TYPES)],
            'course_id' => ['required', 'integer', 'min:1'],
            'target_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'target_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $exists = match ($input['course_type']) {
            'ticket' => TicketProduct::query()->whereKey($input['course_id'])->exists(),
            'membership' => MembershipPlan::query()->whereKey($input['course_id'])->exists(),
            default => Service::query()->whereKey($input['course_id'])->exists(),
        };
        if (! $exists) {
            throw ValidationException::withMessages(['course_id' => __('messages.reporting.course_missing')]);
        }
        $reports->setTarget($input['month'], $input['course_type'], (int) $input['course_id'],
            isset($input['target_amount']) ? (int) $input['target_amount'] : null,
            isset($input['target_count']) ? (int) $input['target_count'] : null, $request->user());

        return back()->with('success', __('messages.reporting.course_target_saved'));
    }

    /** @return array<string, mixed> */
    private function report(Request $request, CourseSalesService $reports, BusinessTime $time): array
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
