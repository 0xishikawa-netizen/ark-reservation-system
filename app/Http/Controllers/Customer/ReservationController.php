<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Payment\ReservationAdjustmentService;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RescheduleReservationRequest;
use App\Models\Reservation;
use App\Queries\CustomerReservationListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(
        Request $request,
        CustomerReservationListQuery $query,
    ): Response {
        $customer = $this->customerFor($request);

        return Inertia::render('Customer/Reservations/Index', [
            'reservations' => $query->get((int) $customer->user_id),
        ]);
    }

    public function show(
        Request $request,
        Reservation $reservation,
        ReservationAdjustmentService $adjustments,
    ): Response {
        $this->customerFor($request);
        $this->authorize('view', $reservation);
        $reservation->loadMissing([
            'service:id,name,duration_min,price,color',
            'staff:user_id,display_name',
        ]);
        $canModify = $reservation->status === ReservationStatus::Confirmed
            && $reservation->starts_at->isFuture();
        $addon = $adjustments->inFlightAddon($reservation);

        return Inertia::render('Customer/Reservations/Show', [
            'reservation' => [
                'id' => (int) $reservation->id,
                'service_id' => (int) $reservation->service_id,
                'service_name' => $reservation->service->name,
                'duration_min' => (int) $reservation->service->duration_min,
                'price' => (int) $reservation->service->price,
                'color' => $reservation->service->color,
                'staff_id' => $reservation->staff_id === null
                    ? null
                    : (int) $reservation->staff_id,
                'staff_name' => $reservation->staff?->display_name,
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $reservation->ends_at->format('Y-m-d H:i:s'),
                'status' => $reservation->status->value,
                'payment_method' => $reservation->payment_method->value,
                'version' => $reservation->version,
                'cancel_reason' => $reservation->cancel_reason,
                'can_cancel' => $canModify,
                'can_reschedule' => $canModify,
            ],
            'addon_payment' => $addon === null ? null : [
                'id' => (int) $addon->id,
                'amount' => (int) $addon->amount,
                'status' => $addon->status->value,
            ],
        ]);
    }

    public function update(
        RescheduleReservationRequest $request,
        Reservation $reservation,
        AvailabilityService $availabilityService,
        ReservationService $reservationService,
    ): RedirectResponse {
        $this->authorize('update', $reservation);
        $user = $this->userFor($request);
        $validated = $request->validated();
        $startsAt = CarbonImmutable::parse((string) $validated['starts_at']);
        $staffId = isset($validated['staff_id'])
            ? (int) $validated['staff_id']
            : null;

        if ($staffId === null) {
            $candidate = collect($availabilityService->openStartTimes(
                (int) $reservation->service_id,
                null,
                null,
                $startsAt->startOfDay(),
            ))->first(
                static fn (array $slot): bool => $slot['starts_at'] === $startsAt->format('Y-m-d H:i:s'),
            );

            $staffId = is_array($candidate)
                ? ($candidate['available_staff_ids'][0] ?? null)
                : null;

            if ($staffId === null) {
                throw new SlotUnavailableException;
            }
        }

        $reservation = $reservationService->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: $staffId,
            boothId: $reservation->booth_id === null
                ? null
                : (int) $reservation->booth_id,
            startsAt: $startsAt,
            expectedVersion: (int) $validated['version'],
            actorUserId: (int) $user->id,
            adminContext: false,
        ));

        return redirect()
            ->route('mypage.reservations.show', $reservation)
            ->with('success', __('messages.reservation.rescheduled'));
    }

    public function destroy(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $this->authorize('delete', $reservation);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $reason = isset($validated['reason'])
            ? trim((string) $validated['reason'])
            : null;

        $reservationService->cancel(
            $reservation,
            $reason === '' ? null : $reason,
            $request->user(),
        );

        return redirect()
            ->route('mypage.reservations.index')
            ->with('success', __('messages.reservation.canceled'));
    }
}
