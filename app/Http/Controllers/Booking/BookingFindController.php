<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Domain\Auth\SmsOtpService;
use App\Domain\Reservation\GuestReservationTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\SendGuestLookupCodeRequest;
use App\Http\Requests\Booking\VerifyGuestLookupCodeRequest;
use App\Models\Customer;
use App\Models\Reservation;
use App\Support\Security\PiiHasher;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class BookingFindController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Booking/Find', [
            'code_sent' => (bool) session('booking_find_code_sent', false),
        ]);
    }

    public function sendCode(
        SendGuestLookupCodeRequest $request,
        SmsOtpService $otp,
    ): RedirectResponse {
        $validated = $request->validated();

        // 顧客の有無を調べる前に常に同じ送信処理・応答を行い、存在を推測させない。
        $otp->sendForPhone(
            (string) $validated['phone'],
            SmsOtpService::PURPOSE_GUEST_LOOKUP,
            $request->ip(),
        );

        return redirect()
            ->route('booking.find.show')
            ->with('booking_find_code_sent', true)
            ->with('success', __('messages.otp.sent_enter_code'));
    }

    public function verify(
        VerifyGuestLookupCodeRequest $request,
        SmsOtpService $otp,
        GuestReservationTokenService $guestTokens,
    ): Response {
        $validated = $request->validated();
        $phone = (string) $validated['phone'];

        $otp->verifyForPhone(
            $phone,
            (string) $validated['code'],
            SmsOtpService::PURPOSE_GUEST_LOOKUP,
        );

        $phoneHmac = PiiHasher::phoneHmac($phone);
        $customerIds = Customer::query()
            ->where('phone_hmac', $phoneHmac)
            ->pluck('user_id');

        $reservations = Reservation::query()
            ->whereIn('customer_id', $customerIds)
            ->with(['service:id,name'])
            ->orderByDesc('starts_at')
            ->get()
            ->map(function (Reservation $reservation) use ($guestTokens): array {
                $token = $guestTokens->issue($reservation);

                return [
                    'id' => (int) $reservation->id,
                    'date' => $reservation->starts_at->toDateString(),
                    'time' => $reservation->starts_at->format('H:i'),
                    'service_name' => $reservation->service->name,
                    'status' => $reservation->status->value,
                    'confirmation_url' => route('booking.confirmation.show', ['selector' => $token]),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Booking/FindResults', [
            'reservations' => $reservations,
        ]);
    }
}
