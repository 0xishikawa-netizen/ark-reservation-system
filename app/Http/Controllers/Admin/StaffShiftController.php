<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\StaffShift\CreateShift;
use App\Actions\StaffShift\DeleteShift;
use App\Actions\StaffShift\UpdateShift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffShiftRequest;
use App\Http\Requests\Admin\UpdateStaffShiftRequest;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Queries\StaffListQuery;
use App\Queries\StaffShiftListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffShiftController extends Controller
{
    public function index(
        Request $request,
        StaffShiftListQuery $shiftQuery,
        StaffListQuery $staffQuery,
    ): Response {
        $validated = $request->validate([
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $defaultFrom = CarbonImmutable::now()->startOfWeek();
        $from = isset($validated['from'])
            ? CarbonImmutable::parse((string) $validated['from'])->startOfDay()
            : $defaultFrom;
        $to = isset($validated['to'])
            ? CarbonImmutable::parse((string) $validated['to'])->endOfDay()
            : $from->endOfWeek();
        $staffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;

        return Inertia::render('Admin/StaffShifts/Index', [
            'staff' => $staffQuery->get()->map(fn (Staff $staff): array => [
                'user_id' => $staff->user_id,
                'display_name' => $staff->display_name,
                'is_bookable' => $staff->is_bookable,
            ])->values(),
            'shifts' => $shiftQuery->get($staffId, $from, $to)
                ->map(fn (StaffShift $shift): array => [
                    'id' => $shift->id,
                    'staff_id' => $shift->staff_id,
                    'staff_display_name' => $shift->staff->display_name,
                    'work_date' => $shift->work_date->format('Y-m-d'),
                    'start_at' => substr((string) $shift->start_at, 0, 5),
                    'end_at' => substr((string) $shift->end_at, 0, 5),
                ])->values(),
            'filters' => [
                'staff_id' => $staffId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function store(
        StoreStaffShiftRequest $request,
        CreateShift $createShift,
    ): RedirectResponse {
        $createShift->execute($request->validated(), $request->user());

        return back()->with('success', '勤務枠を追加しました。');
    }

    public function update(
        UpdateStaffShiftRequest $request,
        StaffShift $staffShift,
        UpdateShift $updateShift,
    ): RedirectResponse {
        $updateShift->execute($staffShift, $request->validated(), $request->user());

        return back()->with('success', '勤務枠を更新しました。');
    }

    public function destroy(
        Request $request,
        StaffShift $staffShift,
        DeleteShift $deleteShift,
    ): RedirectResponse {
        $deleteShift->execute($staffShift, $request->user());

        return back()->with('success', '勤務枠を削除しました。');
    }
}
