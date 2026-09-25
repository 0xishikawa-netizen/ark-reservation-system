<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\StaffAttendanceService;
use App\Http\Controllers\Controller;
use App\Models\StaffAttendance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffAttendanceController extends Controller
{
    public function store(Request $request, StaffAttendanceService $service): RedirectResponse
    {
        $service->save($this->validated($request), $request->user());

        return back()->with('success', __('messages.staff_utilization.attendance_saved'));
    }

    public function update(Request $request, StaffAttendance $attendance, StaffAttendanceService $service): RedirectResponse
    {
        $service->save($this->validated($request), $request->user(), $attendance);

        return back()->with('success', __('messages.staff_utilization.attendance_saved'));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'staff_id' => ['required', 'integer', 'exists:staff,user_id'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'clock_in_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'clock_out_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'status' => ['required', Rule::in(['draft', 'confirmed'])],
            'note' => ['nullable', 'string', 'max:255'],
            'breaks' => ['array', 'max:12'],
            'breaks.*.start_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'breaks.*.end_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'breaks.*.type' => ['nullable', Rule::in(['break', 'meeting', 'training', 'other'])],
            'breaks.*.note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
