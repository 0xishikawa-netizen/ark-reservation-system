<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\ReservationFormOptionsQuery;
use App\Queries\ScheduleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduleController extends Controller
{
    public function index(
        Request $request,
        ScheduleQuery $query,
        ReservationFormOptionsQuery $optionsQuery,
    ): Response {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'view' => ['nullable', Rule::in(['day', 'week'])],
            'axis' => ['nullable', Rule::in(['staff', 'booth'])],
        ]);
        $date = isset($validated['date'])
            ? CarbonImmutable::parse((string) $validated['date'])
            : CarbonImmutable::today();
        $staffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;
        $view = isset($validated['view']) ? (string) $validated['view'] : 'day';
        $axis = isset($validated['axis']) ? (string) $validated['axis'] : 'staff';

        return Inertia::render('Admin/Schedule/Index', [
            ...$query->get($date, $staffId, $view, $axis),
            'staff_options' => $optionsQuery->staff(),
            'filters' => [
                'date' => $date->toDateString(),
                'staff_id' => $staffId,
                'view' => $view,
                'axis' => $axis,
            ],
        ]);
    }
}
