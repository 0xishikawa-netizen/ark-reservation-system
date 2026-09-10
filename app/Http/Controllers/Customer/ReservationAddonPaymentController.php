<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Payment\PaymentService;
use App\Domain\Payment\ReservationAdjustmentService;
use App\Domain\Payment\ReservationCheckoutSaga;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ReservationAddonPaymentController extends Controller
{
    public function show(
        Reservation $reservation,
        ReservationAdjustmentService $adjustments,
        PaymentService $payments,
    ): Response|RedirectResponse {
        $this->authorize('view', $reservation);
        $addon = $adjustments->inFlightAddon($reservation);

        if ($addon === null) {
            return redirect()->route('mypage.reservations.show', $reservation);
        }

        try {
            $session = $payments->ensureIntentSession($addon);
        } catch (PaymentGatewayException) {
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('error', 'ただいま差額のお支払いを開始できません。時間をおいて再度お試しください。');
        }

        $reservation->loadMissing('service:id,name');

        return Inertia::render('Customer/Payments/Checkout', [
            'reservation' => [
                'id' => (int) $reservation->id,
                'service_name' => $reservation->service->name,
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i'),
                'payment_expires_at' => $addon->payment_expires_at?->toIso8601String(),
            ],
            'payment' => [
                'id' => (int) $session->payment->id,
                'amount' => (int) $session->payment->amount,
                'currency' => (string) $session->payment->currency,
                'status' => $session->payment->status->value,
            ],
            'stripe' => [
                'publishable_key' => (string) config('stripe.key'),
                'client_secret' => $session->clientSecret,
            ],
            'checkout' => [
                'kind' => 'addon',
                'sync_url' => route('mypage.reservations.addon.payment.sync', $reservation),
            ],
        ]);
    }

    public function sync(
        Reservation $reservation,
        ReservationAdjustmentService $adjustments,
        ReservationCheckoutSaga $saga,
    ): RedirectResponse {
        $this->authorize('view', $reservation);
        $addon = $adjustments->inFlightAddon($reservation);

        if ($addon === null) {
            return redirect()->route('mypage.reservations.show', $reservation);
        }

        try {
            $addon = $saga->syncAndAdvance($addon);
        } catch (PaymentGatewayDeclinedException) {
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('error', 'カードの承認が得られませんでした。別のお支払い方法をお試しください。');
        } catch (PaymentGatewayException) {
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('info', '差額のお支払い確認に時間がかかっています。確定次第反映されます。');
        }

        if ($addon->status !== PaymentStatus::Succeeded) {
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('info', '差額のお支払いを確認中です。確定次第反映されます。');
        }

        return redirect()
            ->route('mypage.reservations.show', $reservation)
            ->with('success', '差額のお支払いが完了しました。');
    }
}
