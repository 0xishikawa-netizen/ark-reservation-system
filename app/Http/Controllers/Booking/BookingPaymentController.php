<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Domain\Payment\ReservationCheckoutSaga;
use App\Domain\Reservation\GuestReservationTokenService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 未ログイン予約のカード決済画面。
 *
 * Vue へ渡すのは publishable key と client_secret のみ。
 * STRIPE_SECRET は絶対に渡さない。
 */
final class BookingPaymentController extends Controller
{
    public function show(
        string $selector,
        GuestReservationTokenService $guestTokens,
        ReservationCheckoutSaga $saga,
    ): Response|RedirectResponse {
        $tokenParts = explode('.', $selector, 2);

        if (count($tokenParts) !== 2) {
            abort(404);
        }

        $reservation = $guestTokens->resolve($tokenParts[0], $tokenParts[1]);

        if ($reservation === null) {
            abort(404);
        }

        if ($reservation->payment_method !== PaymentMethod::Single
            || $reservation->status !== ReservationStatus::PendingPayment) {
            return redirect()->route('booking.confirmation.show', ['selector' => $selector]);
        }

        try {
            $session = $saga->startCheckout($reservation, null);
        } catch (PaymentGatewayException) {
            return redirect()
                ->route('booking.confirmation.show', ['selector' => $selector])
                ->with('error', __('messages.common.retry_later_payment_start'));
        }

        return Inertia::render('Booking/Checkout', [
            'reservation' => [
                'id' => (int) $reservation->id,
                'service_name' => $reservation->service()->value('name'),
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i'),
                'payment_expires_at' => $reservation->payment_expires_at?->toIso8601String(),
            ],
            'payment' => [
                'id' => (int) $session->payment->id,
                'amount' => (int) $session->payment->amount,
                'currency' => (string) $session->payment->currency,
                'status' => $session->payment->status->value,
            ],
            // 公開情報のみ。secret key は渡さない。
            'stripe' => [
                'publishable_key' => (string) config('stripe.key'),
                'client_secret' => $session->clientSecret,
            ],
            'sync_url' => route('booking.confirmation.payment.sync', ['selector' => $selector]),
            'confirmation_url' => route('booking.confirmation.show', ['selector' => $selector]),
        ]);
    }

    public function sync(
        string $selector,
        GuestReservationTokenService $guestTokens,
        ReservationCheckoutSaga $saga,
    ): RedirectResponse {
        $tokenParts = explode('.', $selector, 2);

        if (count($tokenParts) !== 2) {
            abort(404);
        }

        $reservation = $guestTokens->resolve($tokenParts[0], $tokenParts[1]);

        if ($reservation === null) {
            abort(404);
        }

        if ($reservation->payment_method !== PaymentMethod::Single) {
            return redirect()->route('booking.confirmation.show', ['selector' => $selector]);
        }

        $payment = Payment::query()
            ->where('reservation_id', $reservation->getKey())
            ->where('kind', PaymentKind::Single->value)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Authorized->value,
            ])
            ->orderByDesc('id')
            ->first();

        if ($payment === null) {
            return redirect()->route('booking.confirmation.show', ['selector' => $selector]);
        }

        try {
            $saga->syncAndAdvance($payment);
        } catch (PaymentGatewayDeclinedException) {
            return redirect()
                ->route('booking.confirmation.show', ['selector' => $selector])
                ->with('error', __('messages.payment.card_declined'));
        } catch (PaymentGatewayException) {
            // 結果不明。失敗と断定せず webhook / reconcile による確定を待つ。
            return redirect()
                ->route('booking.confirmation.show', ['selector' => $selector])
                ->with('info', __('messages.payment.confirmation_delayed'));
        }

        $reservation->refresh();

        return redirect()
            ->route('booking.confirmation.show', ['selector' => $selector])
            ->with('success', $reservation->status === ReservationStatus::Confirmed
                ? __('messages.payment.completed_reservation_confirmed')
                : __('messages.payment.reservation_held_processing'));
    }
}
