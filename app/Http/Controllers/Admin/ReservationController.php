<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Reservation\UpdateReservationNotes;
use App\Domain\Payment\ReservationAdjustmentService;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Reservation\StaleReservationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustReservationAmountRequest;
use App\Http\Requests\Admin\StoreAdminReservationRequest;
use App\Http\Requests\Admin\UpdateAdminReservationRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Queries\CustomerLookupQuery;
use App\Queries\ReservationFormOptionsQuery;
use App\Queries\ReservationListQuery;
use App\Queries\ReservationPaymentSummaryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ReservationController extends Controller
{
    public function index(
        Request $request,
        ReservationListQuery $query,
        ReservationFormOptionsQuery $optionsQuery,
    ): Response {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
        ]);
        $date = $this->nullableString($validated['date'] ?? null);
        $status = $this->nullableString($validated['status'] ?? null);
        $staffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;

        return Inertia::render('Admin/Reservations/Index', [
            'reservations' => $query->paginate($date, $staffId, $status),
            'staff' => $optionsQuery->staff(),
            'filters' => [
                'date' => $date,
                'staff_id' => $staffId,
                'status' => $status,
            ],
        ]);
    }

    public function customerSearch(
        Request $request,
        CustomerLookupQuery $query,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($query->search((string) ($validated['q'] ?? '')));
    }

    public function availability(
        Request $request,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($availabilityService->openStartTimes(
            (int) $validated['service_id'],
            isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
            isset($validated['booth_id']) ? (int) $validated['booth_id'] : null,
            CarbonImmutable::parse((string) $validated['date']),
        ));
    }

    public function create(
        Request $request,
        ReservationFormOptionsQuery $query,
    ): Response {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'starts_at' => ['nullable', 'date'],
        ]);

        return Inertia::render('Admin/Reservations/Create', [
            ...$query->get(),
            'prefill' => [
                'date' => $this->nullableString($validated['date'] ?? null),
                'staff_id' => isset($validated['staff_id'])
                    ? (int) $validated['staff_id']
                    : null,
                'starts_at' => $this->nullableString($validated['starts_at'] ?? null),
            ],
        ]);
    }

    public function store(
        StoreAdminReservationRequest $request,
        ReservationService $reservationService,
    ): RedirectResponse {
        $data = $request->validated();
        $startsAt = CarbonImmutable::parse((string) $data['starts_at']);
        $user = $this->userFor($request);

        $reservationService->create(new ReservationInput(
            customerId: (int) $data['customer_id'],
            serviceId: (int) $data['service_id'],
            staffId: isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            boothId: isset($data['booth_id']) ? (int) $data['booth_id'] : null,
            startsAt: $startsAt,
            source: ReservationSource::Admin,
            actorUserId: (int) $user->id,
            notes: $this->normalizeNotes($data['notes'] ?? null),
            adminContext: true,
        ));

        return redirect()
            ->route('admin.schedule.index', ['date' => $startsAt->toDateString()])
            ->with('success', '予約を作成しました。');
    }

    public function edit(
        Reservation $reservation,
        ReservationFormOptionsQuery $query,
        ReservationPaymentSummaryQuery $paymentSummary,
    ): Response {
        return Inertia::render('Admin/Reservations/Edit', [
            'reservation' => $query->reservation((int) $reservation->id),
            'payment_summary' => $paymentSummary->get($reservation),
            ...$query->get(),
        ]);
    }

    public function adjustment(
        AdjustReservationAmountRequest $request,
        Reservation $reservation,
        ReservationAdjustmentService $adjustments,
    ): RedirectResponse {
        $finalAmount = (int) $request->validated('final_amount');

        if ($finalAmount < $adjustments->netReceived($reservation)) {
            abort_unless($request->user()?->can('refund.execute') === true, 403);
        }

        $result = $adjustments->requestAdjustment(
            $reservation,
            $finalAmount,
            $this->userFor($request),
        );

        if (($result['refund_failed'] ?? false) === true) {
            return back()->with(
                'error',
                '差額返金の一部または全部を完了できませんでした。要対応として確認してください。',
            );
        }

        return match ($result['outcome']) {
            'addon_created' => back()->with(
                'success',
                '差額のお支払いリンクを発行しました。お客様のマイページに表示されます。',
            ),
            'addon_reused' => back()->with(
                'info',
                '発行済みの差額支払いリンクをそのまま利用します。',
            ),
            'refunded' => back()->with(
                'success',
                '施術内容変更による差額を返金しました。',
            ),
            'no_change' => back()->with(
                'info',
                '実質受領額と最終施術金額が一致しているため、金銭処理はありません。',
            ),
            default => back()->with(
                'error',
                '差額のお支払いリンクを発行できませんでした。要対応として確認してください。',
            ),
        };
    }

    public function update(
        UpdateAdminReservationRequest $request,
        Reservation $reservation,
        ReservationService $reservationService,
        UpdateReservationNotes $updateNotes,
    ): RedirectResponse {
        $data = $request->validated();
        $startsAt = CarbonImmutable::parse((string) $data['starts_at']);
        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;
        $boothId = isset($data['booth_id']) ? (int) $data['booth_id'] : null;
        $version = (int) $data['version'];
        $notesProvided = array_key_exists('notes', $data);
        $notes = $notesProvided
            ? $this->normalizeNotes($data['notes'])
            : $reservation->notes;
        $notesChanged = $notesProvided && $notes !== $reservation->notes;
        $scheduleChanged = ! $startsAt->equalTo(CarbonImmutable::instance($reservation->starts_at))
            || $staffId !== ($reservation->staff_id === null ? null : (int) $reservation->staff_id)
            || $boothId !== ($reservation->booth_id === null ? null : (int) $reservation->booth_id);
        $user = $this->userFor($request);

        if ($scheduleChanged) {
            $reservation = $reservationService->reschedule(new RescheduleInput(
                reservationId: (int) $reservation->id,
                staffId: $staffId,
                boothId: $boothId,
                startsAt: $startsAt,
                expectedVersion: $version,
                actorUserId: (int) $user->id,
                adminContext: true,
                updateNotes: $notesProvided,
                notes: $notes,
            ));
        } elseif ($notesChanged) {
            $updateNotes->execute($reservation, $notes, $version, $user);
        } elseif ($version !== $reservation->version) {
            throw new StaleReservationException;
        }

        return redirect()
            ->route('admin.reservations.edit', $reservation)
            ->with('success', '予約を更新しました。');
    }

    public function cancel(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $reservationService->cancel(
            $reservation,
            $this->normalizeNotes($validated['reason'] ?? null),
            $request->user(),
        );

        return back()->with('success', '予約をキャンセルしました。');
    }

    public function complete(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $reservationService->markCompleted($reservation, $request->user());

        return back()->with('success', '予約を完了にしました。');
    }

    public function noShow(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $reservationService->markNoShow($reservation, $request->user());

        return back()->with('success', '予約をNo-showにしました。');
    }

    private function userFor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
