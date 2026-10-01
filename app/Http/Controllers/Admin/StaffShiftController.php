<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Reservation\UpdateBookingSettings;
use App\Actions\StaffShift\ClearShiftException;
use App\Actions\StaffShift\CreateShift;
use App\Actions\StaffShift\DeleteShift;
use App\Actions\StaffShift\GenerateShiftsFromTemplates;
use App\Actions\StaffShift\SaveShiftException;
use App\Actions\StaffShift\SaveShiftTemplates;
use App\Actions\StaffShift\UpdateShift;
use App\Domain\Business\StoreCalendarService;
use App\Domain\Reporting\StaffTimesheetService;
use App\Domain\Reservation\BookingWindow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveShiftExceptionRequest;
use App\Http\Requests\Admin\SaveShiftTemplatesRequest;
use App\Http\Requests\Admin\StoreStaffShiftRequest;
use App\Http\Requests\Admin\UpdateBookingSettingsRequest;
use App\Http\Requests\Admin\UpdateStaffShiftRequest;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\StaffShiftException;
use App\Models\StaffShiftTemplate;
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
        BookingWindow $bookingWindow,
        StaffTimesheetService $timesheet,
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
            : $from->addWeeks(5)->endOfWeek();

        $staffList = $staffQuery->get();
        $requestedStaffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;
        $selectedStaffId = $requestedStaffId
            ?? ($staffList->first()?->user_id !== null ? (int) $staffList->first()->user_id : null);

        return Inertia::render('Admin/StaffShifts/Index', [
            'staff' => $staffList->map(fn (Staff $staff): array => [
                'user_id' => $staff->user_id,
                'display_name' => $staff->display_name,
                'is_bookable' => $staff->is_bookable,
            ])->values(),
            'selected_staff_id' => $selectedStaffId,
            'templates' => $selectedStaffId === null ? [] : StaffShiftTemplate::query()
                ->where('staff_id', $selectedStaffId)
                ->orderBy('weekday')
                ->orderBy('start_at')
                ->get(['id', 'staff_id', 'weekday', 'start_at', 'end_at', 'is_active'])
                ->map(static fn (StaffShiftTemplate $t): array => [
                    'id' => (int) $t->id,
                    'weekday' => (int) $t->weekday,
                    'start_at' => substr((string) $t->start_at, 0, 5),
                    'end_at' => substr((string) $t->end_at, 0, 5),
                    'is_active' => (bool) $t->is_active,
                ])->values(),
            'exceptions' => $selectedStaffId === null ? [] : StaffShiftException::query()
                ->where('staff_id', $selectedStaffId)
                ->whereDate('exception_date', '>=', CarbonImmutable::today()->toDateString())
                ->orderBy('exception_date')
                ->get(['id', 'staff_id', 'exception_date', 'is_off', 'note'])
                ->map(static fn (StaffShiftException $e): array => [
                    'id' => (int) $e->id,
                    'exception_date' => $e->exception_date->toDateString(),
                    'is_off' => (bool) $e->is_off,
                    'note' => $e->note,
                ])->values(),
            'shifts' => $shiftQuery->get($selectedStaffId, $from, $to)
                ->map(fn (StaffShift $shift): array => [
                    'id' => $shift->id,
                    'staff_id' => $shift->staff_id,
                    'staff_display_name' => $shift->staff->display_name,
                    'work_date' => $shift->work_date->format('Y-m-d'),
                    'start_at' => substr((string) $shift->start_at, 0, 5),
                    'end_at' => substr((string) $shift->end_at, 0, 5),
                    'origin' => $shift->origin,
                ])->values(),
            // 勤怠一覧：ブッキングボードの勤務枠・休憩・予約から日ごとの行を自動で作る（実績があれば並べる）。
            'timesheet' => $selectedStaffId === null ? [] : $timesheet->forStaff($selectedStaffId, $from, $to),
            'booking' => $this->bookingPayload($bookingWindow),
            'filters' => [
                'staff_id' => $selectedStaffId,
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

        return back()->with('success', __('messages.shift.created'));
    }

    public function update(
        UpdateStaffShiftRequest $request,
        StaffShift $staffShift,
        UpdateShift $updateShift,
    ): RedirectResponse {
        $updateShift->execute($staffShift, $request->validated(), $request->user());

        return back()->with('success', __('messages.shift.updated'));
    }

    public function destroy(
        Request $request,
        StaffShift $staffShift,
        DeleteShift $deleteShift,
    ): RedirectResponse {
        $deleteShift->execute($staffShift, $request->user());

        return back()->with('success', __('messages.shift.deleted'));
    }

    public function saveTemplates(
        SaveShiftTemplatesRequest $request,
        SaveShiftTemplates $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $staff = Staff::query()->findOrFail((int) $validated['staff_id']);

        /** @var list<array{weekday:int,start_at:string,end_at:string}> $entries */
        $entries = array_map(static fn (array $entry): array => [
            'weekday' => (int) $entry['weekday'],
            'start_at' => (string) $entry['start_at'],
            'end_at' => (string) $entry['end_at'],
        ], $validated['entries']);

        $action->execute($staff, $entries, $request->user());

        return back()->with('success', __('messages.shift.templates_saved'));
    }

    public function saveException(
        SaveShiftExceptionRequest $request,
        SaveShiftException $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $staff = Staff::query()->findOrFail((int) $validated['staff_id']);

        $action->execute(
            $staff,
            (string) $validated['exception_date'],
            (bool) $validated['is_off'],
            $validated['note'] ?? null,
            $request->user(),
        );

        return back()->with('success', __('messages.shift.exception_set'));
    }

    public function clearException(
        Request $request,
        StaffShiftException $exception,
        ClearShiftException $action,
    ): RedirectResponse {
        $action->execute($exception, $request->user());

        return back()->with('success', __('messages.shift.exception_cleared'));
    }

    public function generate(
        Request $request,
        GenerateShiftsFromTemplates $action,
    ): RedirectResponse {
        $validated = $request->validate([
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
        ]);

        $result = $action->execute(
            now: null,
            onlyStaffId: isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
            actor: $request->user(),
        );

        return back()->with(
            'success',
            $result['created'] > 0
                ? sprintf('勤務枠を%d件反映しました（%s まで）。', $result['created'], $result['through'])
                : '新たに反映する勤務枠はありませんでした。',
        );
    }

    public function updateBooking(
        UpdateBookingSettingsRequest $request,
        UpdateBookingSettings $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $action->execute([
            'horizon_mode' => (string) $validated['horizon_mode'],
            'horizon_days' => (int) $validated['horizon_days'],
            'release_day_of_month' => (int) $validated['release_day_of_month'],
            'min_lead_minutes' => (int) $validated['min_lead_minutes'],
            'closed_dates' => array_values($validated['closed_dates']),
        ], $request->user());

        return back()->with('success', __('messages.shift.booking_settings_saved'));
    }

    /** @return array<string, mixed> */
    private function bookingPayload(BookingWindow $bookingWindow): array
    {
        $lastBookable = $bookingWindow->lastBookableDate();

        return [
            'horizon_mode' => $bookingWindow->horizonMode(),
            'horizon_days' => $bookingWindow->horizonDays(),
            'release_day_of_month' => $bookingWindow->releaseDayOfMonth(),
            'min_lead_minutes' => $bookingWindow->minLeadMinutes(),
            'closed_dates' => $bookingWindow->closedDates(),
            // 毎週の定休日（ISO 曜日 1=月〜7=日）。店舗全体の休業設定として勤務枠画面にも出す。
            'closed_weekdays' => app(StoreCalendarService::class)->closedWeekdays(),
            'enforced' => $bookingWindow->isHorizonEnforced(),
            'last_bookable_date' => $lastBookable?->toDateString(),
        ];
    }
}
