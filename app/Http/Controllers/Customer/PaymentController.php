<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Payment\ReservationCheckoutSaga;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 顧客のカード決済画面（Stripe Payment Element）。
 *
 * Vue へ渡すのは publishable key と client_secret のみ。
 * STRIPE_SECRET は絶対に渡さない（phase-05 §6-2）。
 */
class PaymentController extends Controller
{
    /**
     * 決済画面。リロードしても新しい決済試行を作らない（同じ payment_operation_id を再利用）。
     */
    public function show(
        Request $request,
        Reservation $reservation,
        ReservationCheckoutSaga $saga,
    ): Response|RedirectResponse {
        $this->authorize('view', $reservation);

        if ($reservation->payment_method !== PaymentMethod::Single) {
            return redirect()->route('mypage.reservations.show', $reservation);
        }

        if ($reservation->status !== ReservationStatus::PendingPayment) {
            // 既に確定・失効している。決済画面は出さない。
            return redirect()->route('mypage.reservations.show', $reservation);
        }

        try {
            $session = $saga->startCheckout($reservation, $request->user());
        } catch (PaymentGatewayException) {
            // 生の Stripe エラーは画面に出さない。再試行できることだけ伝える。
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('error', __('messages.common.retry_later_payment_start'));
        }

        return Inertia::render('Customer/Payments/Checkout', [
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
        ]);
    }

    /**
     * Payment Element での認証後に呼ばれ、Stripe の現在状態を取り込む。
     *
     * client の申告ではなく Stripe から retrieve した結果で判断する。
     */
    public function sync(
        Request $request,
        Reservation $reservation,
        ReservationCheckoutSaga $saga,
    ): RedirectResponse {
        $this->authorize('view', $reservation);

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
            return redirect()->route('mypage.reservations.show', $reservation);
        }

        try {
            $saga->syncAndAdvance($payment);
        } catch (PaymentGatewayDeclinedException) {
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('error', __('messages.payment.card_declined'));
        } catch (PaymentGatewayException) {
            // 結果不明。失敗と断定しない（needs_attention が立ち reconcile 対象になる）。
            return redirect()
                ->route('mypage.reservations.show', $reservation)
                ->with('info', __('messages.payment.confirmation_delayed'));
        }

        $reservation->refresh();

        return redirect()
            ->route('mypage.reservations.show', $reservation)
            ->with('success', $reservation->status === ReservationStatus::Confirmed
                ? __('messages.payment.completed_reservation_confirmed')
                : __('messages.payment.reservation_held_processing'));
    }
}
