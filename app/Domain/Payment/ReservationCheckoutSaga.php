<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Reservation\ReservationPaymentStateMachine;
use App\Domain\Reservation\ReservationStateMachine;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Support\Audit\AuditLogger;
use App\Support\StateMachine\InvalidStateTransitionException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 予約 × カード決済のオーケストレーション（PLAN §7 の補償 Saga を Phase 5 用に最小構成で実装）。
 *
 * 責務は「local commit → Stripe HTTP → local commit」の順序と補償の一元管理のみ。
 * Stripe との通信自体は PaymentService / StripeGateway が担う。
 *
 * **DB transaction を開いたまま Stripe HTTP を呼ばない。**
 * 本クラス内の DB::transaction ブロックからは PaymentService を呼び出さないこと。
 */
final class ReservationCheckoutSaga
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly TicketReservationService $tickets,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * 決済開始。既に有効な試行があれば **新しい payment_operation_id を作らず再利用**する
     * （画面リロード・再送で二重の PaymentIntent を作らないため。phase-05 §1-1）。
     */
    public function startCheckout(Reservation $reservation, ?Authenticatable $actor = null): CheckoutSession
    {
        $this->assertCardCheckout($reservation);

        // [TX1] ローカル側だけを確定させる。Stripe はまだ呼ばない。
        $payment = DB::transaction(function () use ($reservation, $actor): Payment {
            $locked = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ReservationStatus::PendingPayment) {
                throw ValidationException::withMessages([
                    'reservation' => __('messages.payment.checkout_not_allowed'),
                ]);
            }

            if ($locked->payment_expires_at !== null && $locked->payment_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'reservation' => __('messages.payment.checkout_expired'),
                ]);
            }

            $existing = Payment::query()
                ->where('reservation_id', $locked->getKey())
                ->where('kind', PaymentKind::Single->value)
                ->whereIn('status', [
                    PaymentStatus::Pending->value,
                    PaymentStatus::Authorized->value,
                    PaymentStatus::Succeeded->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $service = $locked->service()->firstOrFail();

            $payment = new Payment([
                'customer_id' => $locked->customer_id,
                'reservation_id' => $locked->getKey(),
                'kind' => PaymentKind::Single,
                'provider' => 'stripe',
                // 論理的な決済試行の開始点。ここでだけ発行する。
                'payment_operation_id' => (string) Str::uuid(),
                'amount' => (int) $service->price,
                'currency' => strtolower((string) config('stripe.currency', 'jpy')),
                'capture_method' => (string) config('stripe.capture_method', 'manual'),
                'created_by' => $actor?->getAuthIdentifier(),
            ]);
            $payment->status = PaymentStatus::Pending;
            $payment->save();

            return $payment;
        });

        // [HTTP] transaction の外で Stripe を呼ぶ。
        return $this->payments->ensureIntentSession($payment);
    }

    /**
     * Stripe の現在状態を取り込み、可能なら capture まで進める。
     *
     * 顧客の戻り・webhook・reconcile のいずれからも同じ経路を通す（順序非依存）。
     */
    public function syncAndAdvance(Payment $payment): Payment
    {
        $payment = $this->payments->syncFromStripe($payment);

        // authorize 済みなら capture する。authority=local のため外部予約登録は挟まらない。
        // ただし予約が既に失効・キャンセルされている場合は capture しない。
        // 解放済みの枠に対して課金してしまうため（期限切れ処理と webhook の競合）。
        if ($payment->status === PaymentStatus::Authorized && $this->paymentCanBeCaptured($payment)) {
            $payment = $this->payments->capture($payment);
        }

        return $this->reflectOnReservation($payment);
    }

    /**
     * capture してよい決済かどうか。失効・キャンセル済みなら capture しない。
     */
    private function paymentCanBeCaptured(Payment $payment): bool
    {
        $reservation = Reservation::query()->find($payment->reservation_id);

        if ($payment->kind === PaymentKind::SingleAddon) {
            return $reservation !== null
                && in_array($reservation->status, [
                    ReservationStatus::Confirmed,
                    ReservationStatus::Completed,
                ], true)
                && ($payment->payment_expires_at === null || $payment->payment_expires_at->isFuture());
        }

        return $reservation !== null
            && $reservation->status === ReservationStatus::PendingPayment;
    }

    /**
     * payments.status を reservations 側へ反映する。前進のみ。
     */
    public function reflectOnReservation(Payment $payment): Payment
    {
        // 追加決済は予約本体の決済状態・予約状態を変更しない。
        if ($payment->kind === PaymentKind::SingleAddon) {
            return $payment->refresh();
        }

        $target = match ($payment->status) {
            PaymentStatus::Pending => ReservationPaymentStatus::PendingPayment,
            PaymentStatus::Authorized => ReservationPaymentStatus::Authorized,
            PaymentStatus::Succeeded => ReservationPaymentStatus::Paid,
            PaymentStatus::Voided => ReservationPaymentStatus::Voided,
            PaymentStatus::Failed => ReservationPaymentStatus::Failed,
            PaymentStatus::Refunded => ReservationPaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded => ReservationPaymentStatus::PartiallyRefunded,
        };

        DB::transaction(function () use ($payment, $target): void {
            $reservation = Reservation::query()
                ->whereKey($payment->reservation_id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return;
            }

            $this->advancePaymentStatus($reservation, $target);

            // capture 成功時のみ予約を確定する（authorized では確定しない）。
            if ($target === ReservationPaymentStatus::Paid
                && $reservation->status === ReservationStatus::PendingPayment) {
                $this->advanceStatus($reservation, ReservationStatus::Confirmed);
                $reservation->forceFill(['payment_expires_at' => null])->save();
            }
        });

        return $payment->refresh();
    }

    /**
     * 支払い期限切れの処理。**何度実行しても同じ結果になること**（冪等）。
     *
     * capture 済み（succeeded）の予約は expire しない。自動返金もしない。
     * 返金要否は人間が判断する（needs_attention）。
     */
    public function expireReservation(Reservation $reservation, ?Authenticatable $actor = null): void
    {
        $reservation = Reservation::query()->findOrFail($reservation->getKey());

        if ($reservation->status !== ReservationStatus::PendingPayment) {
            return; // 既に確定・期限切れ・キャンセル済み。二重処理しない。
        }

        $payment = Payment::query()
            ->where('reservation_id', $reservation->getKey())
            ->where('kind', PaymentKind::Single->value)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Authorized->value,
                PaymentStatus::Succeeded->value,
            ])
            ->orderByDesc('id')
            ->first();

        if ($payment !== null && $payment->status === PaymentStatus::Succeeded) {
            // 期限切れ処理中に capture が成立していた。勝手に返金せず要対応にする。
            $this->flagCapturedAtExpiry($payment);

            return;
        }

        // [HTTP] transaction の外で与信を取り消す。
        // PaymentIntent 未作成（Stripe を一度も呼べていない）場合は Stripe を呼ばずローカルだけ閉じる。
        if ($payment !== null && $payment->stripe_payment_intent_id === null) {
            $this->voidLocalOnly($payment);
            $payment = $payment->refresh();
        } elseif ($payment !== null) {
            $payment = $this->payments->cancel($payment);

            if ($payment->status === PaymentStatus::Succeeded) {
                // cancel 直前に capture されていた場合（Stripe の現在状態が succeeded）。
                $this->flagCapturedAtExpiry($payment);

                return;
            }
        }

        // [TX] 予約を期限切れにし、枠と回数券 HOLD を解放する。
        DB::transaction(function () use ($reservation, $actor): void {
            $locked = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ReservationStatus::PendingPayment) {
                return; // 並行実行に勝った側が既に処理済み。
            }

            $this->advancePaymentStatus($locked, ReservationPaymentStatus::Voided);
            $this->advanceStatus($locked, ReservationStatus::Expired);
            $locked->forceFill(['payment_expires_at' => null])->save();

            ReservationResourceSlot::query()
                ->where('reservation_id', $locked->getKey())
                ->delete();

            // カード予約に HOLD は無いが、取り違え防止のため冪等に呼ぶ（無ければ no-op）。
            $this->tickets->release($locked, $actor);
        });

        $this->auditLogger->log(
            'payment.expired',
            $reservation,
            "支払い期限切れ 予約#{$reservation->id} 枠解放・与信取消",
            $actor,
        );
    }

    /**
     * Stripe 側に PaymentIntent が存在しない pending をローカルだけで閉じる。
     */
    private function voidLocalOnly(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                return;
            }

            (new PaymentStateMachine)->apply($locked, 'status', PaymentStatus::Voided->value);
            $locked->forceFill(['voided_at' => now()])->save();
        });
    }

    private function flagCapturedAtExpiry(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'needs_attention' => true,
                'failure_code' => 'captured_after_expiry',
                'failure_message' => __('messages.payment.expired_after_capture_needs_attention'),
            ])->save();
        });

        $this->auditLogger->log(
            'payment.needs_attention',
            $payment,
            "決済#{$payment->id} 期限切れ処理時に capture 済み。返金要否を確認してください。",
        );
    }

    /** @throws ValidationException */
    private function assertCardCheckout(Reservation $reservation): void
    {
        if ($reservation->payment_method !== PaymentMethod::Single) {
            throw ValidationException::withMessages([
                'payment_method' => __('messages.payment.not_card_reservation'),
            ]);
        }
    }

    private function advanceStatus(Reservation $reservation, ReservationStatus $to): void
    {
        try {
            (new ReservationStateMachine)->apply($reservation, 'status', $to->value);
        } catch (InvalidStateTransitionException) {
            // 前進できない＝既に進んでいる。巻き戻さない。
        }
    }

    /**
     * payment_status を目標状態まで前進させる。
     *
     * webhook の遅延・順序逆転で中間状態（authorized など）を観測できないことがあるため、
     * 定義済みの遷移だけを辿って追いつく。巻き戻し方向の経路は存在しないので後退はしない。
     */
    private function advancePaymentStatus(Reservation $reservation, ReservationPaymentStatus $to): void
    {
        $machine = new ReservationPaymentStateMachine;
        $from = $reservation->payment_status->value;

        // failed / voided を経由して先へ進むことは意図しないため除外する。
        $path = $machine->pathTo($from, $to->value, [
            ReservationPaymentStatus::Failed->value,
            ReservationPaymentStatus::Voided->value,
        ]);

        if ($path === [] && $from !== $to->value) {
            // 目標が failed / voided 自身の場合は直接遷移を試す。
            $path = $machine->pathTo($from, $to->value);
        }

        foreach ($path as $next) {
            try {
                $machine->apply($reservation, 'payment_status', $next);
            } catch (InvalidStateTransitionException) {
                // 古いイベントによる巻き戻し要求は無視する。
                return;
            }
        }
    }
}
