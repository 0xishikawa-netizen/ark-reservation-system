<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReservationAdjustmentService
{
    private const REFUND_REASON = '施術内容変更による差額返金';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentStateMachine $paymentStateMachine,
        private readonly AuditLogger $auditLogger,
        private readonly Settings $settings,
    ) {}

    public function netReceived(Reservation $reservation): int
    {
        return $reservation->payments()
            ->whereIn('status', $this->capturedStatuses())
            ->get(['amount', 'refunded_amount'])
            ->sum(static fn (Payment $payment): int => max(
                0,
                (int) $payment->amount - (int) $payment->refunded_amount,
            ));
    }

    public function inFlightAddon(Reservation $reservation): ?Payment
    {
        return $this->activeAddons($reservation)->first();
    }

    public function pendingAddonTotal(Reservation $reservation): int
    {
        return $this->activeAddons($reservation)->sum(
            static fn (Payment $payment): int => (int) $payment->amount,
        );
    }

    public function originalPayment(Reservation $reservation): ?Payment
    {
        return $reservation->payments()
            ->where('kind', PaymentKind::Single->value)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{
     *   outcome: 'addon_created'|'addon_reused'|'refunded'|'no_change'|'addon_failed',
     *   addon_payment_id?: int,
     *   refunded_amount?: int,
     *   refund_failed?: bool,
     *   outstanding_amount?: int
     * }
     */
    public function requestAdjustment(
        Reservation $reservation,
        int $finalAmount,
        Authenticatable $adminActor,
    ): array {
        if ($finalAmount < 0) {
            throw ValidationException::withMessages([
                'final_amount' => __('messages.payment.final_amount_non_negative'),
            ]);
        }

        $reservation = DB::transaction(function () use ($reservation, $finalAmount): Reservation {
            $locked = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->forceFill(['final_amount' => $finalAmount])->save();

            return $locked;
        });

        $net = $this->netReceived($reservation);
        $gap = max(0, $finalAmount - $net);
        $activeAddons = $this->activeAddons($reservation);
        $needed = $gap - $this->pendingAddonTotal($reservation);

        // 現在の不足額と1件の追加決済が一致する場合だけ再利用する。
        if ($gap > 0
            && $needed === 0
            && $activeAddons->count() === 1
            && (int) $activeAddons->first()->amount === $gap) {
            $addon = $activeAddons->first();
            $this->auditLogger->log(
                'reservation.addon_payment_reused',
                $reservation,
                "追加決済#{$addon->id} {$addon->amount}円を再利用",
                $adminActor,
            );

            return ['outcome' => 'addon_reused', 'addon_payment_id' => (int) $addon->id];
        }

        // 値下げ・再変更時に古いリンクから支払われないよう、不一致の追加決済を先に取り消す。
        foreach ($this->cancelableAddons($reservation) as $addon) {
            if (! $this->voidAddon($reservation, $addon, $adminActor)) {
                return ['outcome' => 'addon_failed', 'addon_payment_id' => (int) $addon->id];
            }
        }

        $net = $this->netReceived($reservation);
        $delta = $finalAmount - $net;

        if ($delta === 0) {
            $this->auditLogger->log(
                'reservation.adjustment_no_change',
                $reservation,
                "最終施術金額 {$finalAmount}円・実質受領額と一致",
                $adminActor,
            );

            return ['outcome' => 'no_change'];
        }

        if ($delta > 0) {
            if (! in_array($reservation->status, [
                ReservationStatus::Confirmed,
                ReservationStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'final_amount' => __('messages.payment.addon_only_confirmed_or_completed'),
                ]);
            }

            return $this->requestAddon($reservation, $adminActor);
        }

        return $this->refundDifference($reservation, abs($delta), $adminActor);
    }

    /**
     * @return Collection<int, Payment>
     */
    private function activeAddons(Reservation $reservation): Collection
    {
        return $reservation->payments()
            ->where('kind', PaymentKind::SingleAddon->value)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Authorized->value])
            ->where(static function ($query): void {
                $query->whereNull('payment_expires_at')
                    ->orWhere('payment_expires_at', '>', now());
            })
            ->latest('id')
            ->get();
    }

    /**
     * Expired でも Stripe 側で生きている PI を残さない。
     *
     * @return Collection<int, Payment>
     */
    private function cancelableAddons(Reservation $reservation): Collection
    {
        return $reservation->payments()
            ->where('kind', PaymentKind::SingleAddon->value)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Authorized->value])
            ->latest('id')
            ->get();
    }

    private function voidAddon(
        Reservation $reservation,
        Payment $addon,
        Authenticatable $adminActor,
    ): bool {
        // cancelableAddons 取得後に webhook / sync で settle 済みへ変わっている競合。
        // 既に取消不能なら「処理済み」として扱い、要対応化しない。
        $fresh = $addon->fresh();
        if ($fresh === null || ! in_array($fresh->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            return true;
        }

        try {
            if ($addon->stripe_payment_intent_id !== null) {
                // Stripe HTTP: DB transaction 外。
                $this->payments->cancel($addon);
            } else {
                DB::transaction(function () use ($addon): void {
                    $locked = Payment::query()->whereKey($addon->getKey())->lockForUpdate()->firstOrFail();

                    if (in_array($locked->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
                        $this->paymentStateMachine->apply($locked, 'status', PaymentStatus::Voided->value);
                        $locked->forceFill(['voided_at' => now()])->save();
                    }
                });
            }
        } catch (ValidationException) {
            // cancel() 内で「取消不能」判定になった（直前に settle）。処理済み扱い。
            return true;
        } catch (PaymentGatewayException) {
            $addon->refresh()->forceFill([
                'needs_attention' => true,
                'failure_code' => 'addon_void_failed',
                'failure_message' => '追加決済の取消結果を確認できませんでした。',
            ])->save();
            $this->auditLogger->log(
                'reservation.addon_void_failed',
                $reservation,
                "追加決済#{$addon->id}の取消に失敗",
                $adminActor,
            );

            return false;
        }

        $this->auditLogger->log(
            'reservation.addon_payment_voided',
            $reservation,
            "追加決済#{$addon->id}を取消",
            $adminActor,
        );

        return true;
    }

    /**
     * @return array{
     *   outcome: 'addon_created'|'addon_reused'|'addon_failed'|'no_change',
     *   addon_payment_id?: int
     * }
     */
    private function requestAddon(
        Reservation $reservation,
        Authenticatable $adminActor,
    ): array {
        $created = false;
        $addon = DB::transaction(function () use (
            $reservation,
            $adminActor,
            &$created,
        ): ?Payment {
            $lockedReservation = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existing = $this->inFlightAddon($lockedReservation);

            if ($existing !== null) {
                return $existing;
            }

            // void 後の最新状態で再計算し、並行処理による過剰請求を防ぐ。
            $currentNeeded = max(
                0,
                (int) $lockedReservation->final_amount - $this->netReceived($lockedReservation),
            );

            if ($currentNeeded === 0) {
                return null;
            }

            $addon = new Payment([
                'customer_id' => $lockedReservation->customer_id,
                'reservation_id' => $lockedReservation->getKey(),
                'parent_payment_id' => $this->originalPayment($lockedReservation)?->getKey(),
                'kind' => PaymentKind::SingleAddon,
                'provider' => 'stripe',
                'payment_operation_id' => (string) Str::uuid(),
                'amount' => $currentNeeded,
                'currency' => strtolower((string) config('stripe.currency', 'jpy')),
                'capture_method' => (string) config('stripe.capture_method', 'manual'),
                'payment_expires_at' => now()->addMinutes(
                    (int) $this->settings->get('reservation.hold_minutes', 10),
                ),
                'created_by' => $adminActor->getAuthIdentifier(),
            ]);
            $addon->status = PaymentStatus::Pending;
            $addon->save();
            $created = true;

            return $addon;
        });

        if ($addon === null) {
            $this->auditLogger->log(
                'reservation.adjustment_no_change',
                $reservation,
                '追加決済作成直前の再計算で差額なし',
                $adminActor,
            );

            return ['outcome' => 'no_change'];
        }

        if (! $created) {
            return ['outcome' => 'addon_reused', 'addon_payment_id' => (int) $addon->id];
        }

        try {
            // Stripe HTTP: DB transaction 外。保存済み operation ID で再試行可能。
            $this->payments->ensureIntentSession($addon);
        } catch (PaymentGatewayException) {
            $addon->refresh()->forceFill(['needs_attention' => true])->save();
            $this->auditLogger->log(
                'reservation.addon_payment_failed',
                $reservation,
                "追加決済#{$addon->id} {$addon->amount}円のPaymentIntent作成に失敗",
                $adminActor,
            );

            return ['outcome' => 'addon_failed', 'addon_payment_id' => (int) $addon->id];
        }

        $this->auditLogger->log(
            'reservation.addon_payment_requested',
            $reservation,
            "追加決済#{$addon->id} {$addon->amount}円を発行",
            $adminActor,
        );

        return ['outcome' => 'addon_created', 'addon_payment_id' => (int) $addon->id];
    }

    /**
     * @return array{
     *   outcome: 'refunded',
     *   refunded_amount: int,
     *   refund_failed?: bool,
     *   outstanding_amount?: int
     * }
     */
    private function refundDifference(
        Reservation $reservation,
        int $toRefund,
        Authenticatable $adminActor,
    ): array {
        $refunded = 0;

        if ($reservation->payments()
            ->whereHas('refunds', static fn ($query) => $query->where('status', RefundStatus::Pending->value))
            ->exists()) {
            // 結果不明の返金がある間は別 Payment への返金を重ねない。
            return $this->refundFailureResult($reservation, $toRefund, 0, $adminActor);
        }

        $capturedPayments = $reservation->payments()
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        foreach ($capturedPayments as $payment) {
            $reservedRefunds = (int) $payment->refunds()
                ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])
                ->sum('amount');
            $available = max(0, (int) $payment->amount - max(
                (int) $payment->refunded_amount,
                $reservedRefunds,
            ));
            $slice = min($toRefund - $refunded, $available);

            if ($slice <= 0) {
                continue;
            }

            try {
                // Stripe HTTP: DB transaction 外。
                $this->payments->refund($payment, $slice, self::REFUND_REASON, $adminActor);
                $refunded += $slice;
            } catch (PaymentGatewayException) {
                $payment->refresh()->forceFill(['needs_attention' => true])->save();

                return $this->refundFailureResult(
                    $reservation,
                    $toRefund,
                    $refunded,
                    $adminActor,
                );
            }

            if ($refunded >= $toRefund) {
                break;
            }
        }

        if ($refunded < $toRefund) {
            return $this->refundFailureResult($reservation, $toRefund, $refunded, $adminActor);
        }

        $this->auditLogger->log(
            'reservation.adjustment_refunded',
            $reservation,
            "差額 {$refunded}円を返金",
            $adminActor,
        );

        return ['outcome' => 'refunded', 'refunded_amount' => $refunded];
    }

    /**
     * @return array{
     *   outcome: 'refunded',
     *   refunded_amount: int,
     *   refund_failed: true,
     *   outstanding_amount: int
     * }
     */
    private function refundFailureResult(
        Reservation $reservation,
        int $requested,
        int $refunded,
        Authenticatable $adminActor,
    ): array {
        $outstanding = max(0, $requested - $refunded);
        $this->auditLogger->log(
            'reservation.adjustment_refund_failed',
            $reservation,
            "返金済み {$refunded}円・未返金 {$outstanding}円",
            $adminActor,
        );

        return [
            'outcome' => 'refunded',
            'refunded_amount' => $refunded,
            'refund_failed' => true,
            'outstanding_amount' => $outstanding,
        ];
    }

    /** @return list<string> */
    private function capturedStatuses(): array
    {
        return [
            PaymentStatus::Succeeded->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ];
    }
}
