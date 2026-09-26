<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\DailyBusinessNoteService;
use App\Http\Controllers\Controller;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class DailyBusinessNoteController extends Controller
{
    public function index(Request $request, DailyBusinessNoteService $notes, BusinessTime $businessTime): Response
    {
        $input = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $monthKey = $input['month'] ?? $businessTime->businessDate()->format('Y-m');
        $month = CarbonImmutable::createFromFormat('!Y-m', $monthKey, $businessTime->timezone());
        if ($month === false || $month->year < 2000 || $month->year > 2100) {
            throw ValidationException::withMessages(['month' => __('messages.reporting.invalid_month')]);
        }
        $existing = $notes->forMonth($month->year, $month->month);
        $days = [];
        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $date = $month->setDay($day);
            $key = $date->toDateString();
            $note = $existing[$key] ?? null;
            $days[] = [
                'business_date' => $key,
                'day' => $day,
                'weekday' => $date->isoFormat('ddd'),
                'business_condition' => $note?->business_condition ?? '',
                'reflection' => $note?->reflection ?? '',
            ];
        }

        return Inertia::render('Admin/Reports/DailyNotes', [
            'month' => $monthKey,
            'days' => $days,
            'editable' => $request->user()?->can('reports.manage') ?? false,
            'indexEndpoint' => route('admin.reports.daily-notes'),
        ]);
    }

    public function update(Request $request, string $businessDate, DailyBusinessNoteService $notes): RedirectResponse
    {
        Validator::make(['business_date' => $businessDate], [
            'business_date' => ['required', 'date_format:Y-m-d'],
        ])->validate();
        $input = $request->validate([
            'business_condition' => ['nullable', 'string', 'max:10000'],
            'reflection' => ['nullable', 'string', 'max:10000'],
        ]);
        $notes->save($businessDate, $input['business_condition'] ?? null, $input['reflection'] ?? null, $request->user());

        return back()->with('success', __('messages.reporting.daily_note_saved'));
    }
}
