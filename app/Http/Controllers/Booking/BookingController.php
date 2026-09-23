<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Customer\CreateProvisionalCustomer;
use App\Actions\Reservation\RecordReservationInflowChannel;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\GuestReservationTokenService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Reservation\WeekAvailabilityBuilder;
use App\Enums\Reservation\InflowChannel;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\AvailabilityRequest;
use App\Http\Requests\Booking\StoreGuestReservationRequest;
use App\Http\Requests\Booking\WeekAvailabilityRequest;
use App\Notifications\GuestReservationConfirmed;
use App\Queries\OnlineBookableServiceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class BookingController extends Controller
{
    public function create(OnlineBookableServiceQuery $query): Response
    {
        return Inertia::render('Booking/Index', [
            'services' => $query->get(),
        ]);
    }

    public function availability(
        AvailabilityRequest $request,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json($availabilityService->openStartTimes(
            (int) $validated['service_id'],
            isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
            null,
            CarbonImmutable::parse((string) $validated['date']),
        ));
    }

    public function weekAvailability(
        WeekAvailabilityRequest $request,
        WeekAvailabilityBuilder $builder,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json($builder->build(
            (int) $validated['service_id'],
            isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
            CarbonImmutable::parse((string) $validated['start_date']),
        ));
    }

    public function store(
        StoreGuestReservationRequest $request,
        CreateProvisionalCustomer $createProvisionalCustomer,
        AvailabilityService $availabilityService,
        ReservationService $reservationService,
        RecordReservationInflowChannel $recordInflowChannel,
        GuestReservationTokenService $guestTokens,
    ): RedirectResponse {
        $validated = $request->validated();
        $serviceId = (int) $validated['service_id'];
        $startsAt = CarbonImmutable::parse((string) $validated['starts_at']);
        $staffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;
        $paymentMethod = PaymentMethod::from((string) $validated['payment_method']);

        if ($staffId === null) {
            $candidate = collect($availabilityService->openStartTimes(
                $serviceId,
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

        $customer = $createProvisionalCustomer->executeGuest([
            'name' => (string) $validated['name'],
            'phone' => (string) $validated['phone'],
            'email' => $validated['email'] ?? null,
        ]);

        $boothId = $availabilityService->firstAvailableBooth($serviceId, $startsAt);

        $reservation = $reservationService->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: $serviceId,
            staffId: $staffId,
            boothId: $boothId,
            startsAt: $startsAt,
            source: ReservationSource::ArkWeb,
            actorUserId: null,
            notes: $this->normalizeNotes($validated['notes'] ?? null),
            adminContext: false,
            paymentMethod: $paymentMethod,
        ));

        $recordInflowChannel->execute(
            $reservation,
            InflowChannel::fromUntrustedSource($validated['source'] ?? null),
        );
        $opaqueToken = $guestTokens->issue($reservation);

        if (isset($validated['email']) && trim((string) $validated['email']) !== '') {
            $reservation->loadMissing(['service:id,name', 'staff:user_id,display_name']);
            $customer->user->notify(new GuestReservationConfirmed(
                reservation: $reservation,
                confirmationUrl: route('booking.confirmation.show', ['selector' => $opaqueToken]),
            ));
        }

        if ($paymentMethod === PaymentMethod::Single) {
            return redirect()
                ->route('booking.confirmation.checkout', ['selector' => $opaqueToken])
                ->with('info', __('messages.reservation.slot_held_pay_next'));
        }

        return redirect()
            ->route('booking.confirmation.show', ['selector' => $opaqueToken])
            ->with('success', __('messages.reservation.confirmed'));
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
