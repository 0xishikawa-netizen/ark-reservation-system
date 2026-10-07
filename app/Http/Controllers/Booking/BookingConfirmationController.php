<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\GuestReservationTokenService;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BookingConfirmationController extends Controller
{
    public function show(
        string $selector,
        GuestReservationTokenService $guestTokens,
    ): Response {
        $tokenParts = explode('.', $selector, 2);

        if (count($tokenParts) !== 2) {
            abort(404);
        }

        $reservation = $guestTokens->resolve($tokenParts[0], $tokenParts[1]);

        if ($reservation === null) {
            abort(404);
        }

        $reservation->loadMissing([
            'service:id,name',
            'staff:user_id,display_name',
            'customer.user:id,email,password',
        ]);

        $user = $reservation->customer->user;
        $usesPlaceholderEmail = Str::endsWith(Str::lower($user->email), '@ark.invalid');

        return Inertia::render('Booking/Confirmation', [
            'reservation' => [
                'id' => (int) $reservation->id,
                'date' => $reservation->starts_at->toDateString(),
                'time' => $reservation->starts_at->format('H:i'),
                'service_name' => $reservation->service->name,
                'staff_name' => $reservation->staff?->display_name,
                'status' => $reservation->status->value,
                'payment_status' => $reservation->payment_status->value,
                'payment_method' => $reservation->payment_method->value,
                'version' => $reservation->version,
                'can_cancel' => $reservation->status === ReservationStatus::PendingPayment
                    || ($reservation->status === ReservationStatus::Confirmed
                        && $reservation->starts_at->isFuture()),
                'can_reschedule' => $reservation->status === ReservationStatus::Confirmed
                    && $reservation->starts_at->isFuture(),
            ],
            'management' => [
                'availability_url' => route('booking.availability', [
                    'service_id' => $reservation->service_id,
                    'staff_id' => $reservation->staff_id,
                ]),
                'staff_id' => $reservation->staff_id === null
                    ? null
                    : (int) $reservation->staff_id,
                'reschedule_url' => route('booking.confirmation.reschedule', ['selector' => $selector]),
                'cancel_url' => route('booking.confirmation.cancel', ['selector' => $selector]),
                'checkout_url' => route('booking.confirmation.checkout', ['selector' => $selector]),
                'upgrade_url' => route('booking.confirmation.upgrade', ['selector' => $selector]),
            ],
            'member_upgrade' => [
                'available' => $user->password === null,
                'email' => $usesPlaceholderEmail ? '' : $user->email,
                'email_required' => $usesPlaceholderEmail,
            ],
        ]);
    }

    public function reschedule(
        Request $request,
        string $selector,
        GuestReservationTokenService $guestTokens,
        AvailabilityService $availabilityService,
        ReservationService $reservationService,
    ): RedirectResponse {
        $tokenParts = explode('.', $selector, 2);

        if (count($tokenParts) !== 2) {
            abort(404);
        }

        $reservation = $guestTokens->resolve($tokenParts[0], $tokenParts[1]);

        if ($reservation === null) {
            abort(404);
        }

        // トークン検証後に入力を検証し、不正トークンの応答を常に同じ 404 にそろえる。
        $validated = $request->validate([
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $startsAt = CarbonImmutable::parse((string) $validated['starts_at']);

        if (! SlotKey::fromSettings()->isBoundary($startsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => __('messages.reservation.non_boundary_start'),
            ]);
        }

        $staffId = isset($validated['staff_id'])
            ? (int) $validated['staff_id']
            : null;

        if ($staffId === null && ! $reservation->service()->firstOrFail()->requires_staff) {
            $staffId = $reservation->staff_id === null
                ? null
                : (int) $reservation->staff_id;
        } elseif ($staffId === null) {
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

        $reservationService->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: $staffId,
            boothId: $reservation->booth_id === null
                ? null
                : (int) $reservation->booth_id,
            startsAt: $startsAt,
            expectedVersion: (int) $validated['version'],
            actorUserId: null,
            adminContext: false,
        ));

        return redirect()
            ->route('booking.confirmation.show', ['selector' => $selector])
            ->with('success', __('messages.reservation.rescheduled'));
    }

    public function cancel(
        Request $request,
        string $selector,
        GuestReservationTokenService $guestTokens,
        ReservationService $reservationService,
    ): RedirectResponse {
        $tokenParts = explode('.', $selector, 2);

        if (count($tokenParts) !== 2) {
            abort(404);
        }

        $reservation = $guestTokens->resolve($tokenParts[0], $tokenParts[1]);

        if ($reservation === null) {
            abort(404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $reason = isset($validated['reason'])
            ? trim((string) $validated['reason'])
            : null;

        $reservationService->cancel(
            $reservation,
            $reason === '' ? null : $reason,
            null,
            customerContext: true,
        );

        return redirect()
            ->route('booking.confirmation.show', ['selector' => $selector])
            ->with('success', __('messages.reservation.canceled'));
    }
}
