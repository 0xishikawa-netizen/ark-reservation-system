<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reserve;

use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Reservation\WeekAvailabilityBuilder;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\AvailabilityRequest;
use App\Http\Requests\Booking\WeekAvailabilityRequest;
use App\Http\Requests\Customer\StoreReservationRequest;
use App\Models\Membership;
use App\Models\TicketWallet;
use App\Queries\OnlineBookableServiceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReserveController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function create(
        Request $request,
        OnlineBookableServiceQuery $query,
    ): Response {
        $customer = $this->customerFor($request);
        $ledger = app(TicketLedgerService::class);
        $wallets = TicketWallet::query()
            ->where('customer_id', $customer->user_id)
            ->active()
            ->with('product:id,name')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();
        $ticketWallets = $wallets
            ->map(fn (TicketWallet $wallet): array => [
                'id' => (int) $wallet->id,
                'product_name' => $wallet->product->name,
                'available' => $ledger->available($wallet),
                'expires_at' => $wallet->expires_at->toDateString(),
            ])
            ->values();
        $membership = Membership::query()
            ->where('customer_id', $customer->user_id)
            ->bookable()
            ->latest('id')
            ->first();

        return Inertia::render('Customer/Reserve/Index', [
            'services' => $query->get(),
            'ticket' => [
                'available_total' => $ticketWallets->sum('available'),
                'wallets' => $ticketWallets->all(),
            ],
            'membership' => [
                'available' => $membership === null
                    ? 0
                    : app(MembershipLedgerService::class)->available($membership),
                'status' => $membership?->status->value,
            ],
        ]);
    }

    public function availability(
        AvailabilityRequest $request,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $this->customerFor($request);

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
        $this->customerFor($request);
        $validated = $request->validated();

        return response()->json($builder->build(
            (int) $validated['service_id'],
            isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
            CarbonImmutable::parse((string) $validated['start_date']),
        ));
    }

    public function store(
        StoreReservationRequest $request,
        AvailabilityService $availabilityService,
        ReservationService $reservationService,
    ): RedirectResponse {
        $user = $this->userFor($request);
        $customer = $this->customerFor($request);
        $validated = $request->validated();
        $serviceId = (int) $validated['service_id'];
        $startsAt = CarbonImmutable::parse((string) $validated['starts_at']);
        $staffId = isset($validated['staff_id'])
            ? (int) $validated['staff_id']
            : null;
        $paymentMethod = match ($request->string('payment_method')->toString()) {
            'ticket' => PaymentMethod::Ticket,
            'membership' => PaymentMethod::Membership,
            // single = Stripe カード決済（pending_payment で枠を確保し、capture 後に確定）
            'card' => PaymentMethod::Single,
            default => PaymentMethod::Onsite,
        };

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

        $boothId = $availabilityService->firstAvailableBooth($serviceId, $startsAt);

        $reservation = $reservationService->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: $serviceId,
            staffId: $staffId,
            boothId: $boothId,
            startsAt: $startsAt,
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $user->id,
            notes: null,
            adminContext: false,
            paymentMethod: $paymentMethod,
        ));

        // カード決済は与信・capture が済むまで確定しない。決済画面へ送る。
        if ($paymentMethod === PaymentMethod::Single) {
            return redirect()
                ->route('mypage.reservations.checkout', $reservation)
                ->with('info', __('messages.reservation.pay_to_confirm'));
        }

        return redirect()
            ->route('mypage.reservations.show', $reservation)
            ->with('success', __('messages.reservation.confirmed'));
    }
}
