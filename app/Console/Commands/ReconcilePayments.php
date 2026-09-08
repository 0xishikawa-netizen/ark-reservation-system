<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\Gateway\StripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ローカルの決済状態と Stripe の現在オブジェクトを突合する（PLAN §9 / §14）。
 *
 * 既定は read-only。差異があれば非 zero exit で終了する。
 *
 * `--repair` は **安全な派生状態のみ** を修復する。
 * Stripe へ capture / refund / cancel を行う修復は絶対に実装しない（金銭操作は明示 Action のみ）。
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
        {--repair : 安全な派生状態のみ修復する（Stripe への金銭操作は行わない）}
        {--limit=500 : 検査する最大件数}
        {--stripe : Stripe API に問い合わせて現在状態と突合する}';

    protected $description = '決済のローカル状態と Stripe / 予約状態の矛盾を検出する（既定 read-only）';

    public function handle(StripeGateway $gateway, AuditLogger $auditLogger): int
    {
        $repair = (bool) $this->option('repair');
        $useStripe = (bool) $this->option('stripe');
        $limit = max(1, (int) $this->option('limit'));

        /** @var list<array{payment_id: int|null, kind: string, detail: string}> $issues */
        $issues = [];
        $repaired = 0;

        $payments = Payment::query()->orderByDesc('id')->limit($limit)->get();

        foreach ($payments as $payment) {
            // 1. refunded_amount キャッシュ vs payment_refunds（正本）
            $ledgerRefunded = (int) PaymentRefund::query()
                ->where('payment_id', $payment->getKey())
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount');

            if ($ledgerRefunded !== (int) $payment->refunded_amount) {
                $issues[] = [
                    'payment_id' => (int) $payment->id,
                    'kind' => 'refunded_amount_mismatch',
                    'detail' => "cache={$payment->refunded_amount} ledger={$ledgerRefunded}",
                ];

                if ($repair) {
                    $this->repairRefundedAmount($payment, $ledgerRefunded);
                    $repaired++;
                }
            }

            // 2. 返金額が決済額を超えていないか（超過は重大。修復しない）
            if ($ledgerRefunded > (int) $payment->amount) {
                $issues[] = [
                    'payment_id' => (int) $payment->id,
                    'kind' => 'over_refund',
                    'detail' => "refunded={$ledgerRefunded} amount={$payment->amount}",
                ];
            }

            // 3. 未解決の要対応
            if ($payment->needs_attention) {
                $issues[] = [
                    'payment_id' => (int) $payment->id,
                    'kind' => 'needs_attention',
                    'detail' => (string) ($payment->failure_code ?? 'unknown'),
                ];
            }

            // 4. 予約側の payment_status との整合
            $reservation = $payment->reservation_id === null
                ? null
                : Reservation::query()->find($payment->reservation_id);

            if ($reservation !== null) {
                $expected = $this->expectedReservationPaymentStatus($payment->status);

                if ($expected !== null && $reservation->payment_status !== $expected) {
                    $issues[] = [
                        'payment_id' => (int) $payment->id,
                        'kind' => 'reservation_payment_status_mismatch',
                        'detail' => "payment={$payment->status->value} reservation={$reservation->payment_status->value}",
                    ];
                }

                // 5. 支払い済みなのに予約が確定していない
                if ($payment->status === PaymentStatus::Succeeded
                    && $reservation->status === ReservationStatus::PendingPayment) {
                    $issues[] = [
                        'payment_id' => (int) $payment->id,
                        'kind' => 'paid_but_reservation_not_confirmed',
                        'detail' => "reservation#{$reservation->id}",
                    ];
                }
            }

            // 6. Stripe の現在オブジェクトとの突合（--stripe 指定時のみ）
            if ($useStripe && $payment->stripe_payment_intent_id !== null) {
                try {
                    $result = $gateway->retrievePaymentIntent($payment->stripe_payment_intent_id);

                    $localCaptured = $payment->status === PaymentStatus::Succeeded
                        || $payment->status === PaymentStatus::PartiallyRefunded
                        || $payment->status === PaymentStatus::Refunded;
                    $stripeCaptured = $result->amountReceived > 0 || $result->status === 'succeeded';

                    if ($localCaptured !== $stripeCaptured) {
                        $issues[] = [
                            'payment_id' => (int) $payment->id,
                            'kind' => 'capture_state_mismatch',
                            'detail' => "local={$payment->status->value} stripe={$result->status}",
                        ];
                    }

                    if ($result->amount !== (int) $payment->amount) {
                        $issues[] = [
                            'payment_id' => (int) $payment->id,
                            'kind' => 'amount_mismatch',
                            'detail' => "local={$payment->amount} stripe={$result->amount}",
                        ];
                    }

                    if ($result->refundedAmount !== $ledgerRefunded) {
                        $issues[] = [
                            'payment_id' => (int) $payment->id,
                            'kind' => 'stripe_refund_mismatch',
                            'detail' => "local={$ledgerRefunded} stripe={$result->refundedAmount}",
                        ];
                    }
                } catch (PaymentGatewayException $exception) {
                    $issues[] = [
                        'payment_id' => (int) $payment->id,
                        'kind' => 'stripe_unreachable',
                        'detail' => $exception::class,
                    ];
                }
            }
        }

        // 7. 期限を過ぎたまま滞留している仮予約
        $stale = Reservation::query()
            ->where('status', ReservationStatus::PendingPayment->value)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->count();

        if ($stale > 0) {
            $issues[] = [
                'payment_id' => null,
                'kind' => 'stale_pending_payment',
                'detail' => "{$stale} 件が期限超過のまま pending_payment",
            ];
        }

        $this->report($issues, $repaired, $repair);

        if ($repaired > 0) {
            $auditLogger->log(
                'payment.reconciled',
                null,
                "payments:reconcile --repair 派生状態を {$repaired} 件修復",
            );
        }

        // 修復済みのものを除いた残存差異で判定する。
        $remaining = count($issues) - $repaired;

        return $remaining > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function repairRefundedAmount(Payment $payment, int $ledgerRefunded): void
    {
        DB::transaction(function () use ($payment, $ledgerRefunded): void {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['refunded_amount' => $ledgerRefunded])->save();
        });
    }

    private function expectedReservationPaymentStatus(PaymentStatus $status): ?ReservationPaymentStatus
    {
        return match ($status) {
            PaymentStatus::Authorized => ReservationPaymentStatus::Authorized,
            PaymentStatus::Succeeded => ReservationPaymentStatus::Paid,
            PaymentStatus::Voided => ReservationPaymentStatus::Voided,
            PaymentStatus::Refunded => ReservationPaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded => ReservationPaymentStatus::PartiallyRefunded,
            // pending / failed は予約側の表現が一意に決まらないため検査しない。
            default => null,
        };
    }

    /** @param list<array{payment_id: int|null, kind: string, detail: string}> $issues */
    private function report(array $issues, int $repaired, bool $repair): void
    {
        if ($issues === []) {
            $this->info('差異はありません。');

            return;
        }

        // PII は出力しない。ID と種別・要約のみ。
        $this->table(
            ['payment_id', 'kind', 'detail'],
            array_map(static fn (array $i): array => [
                $i['payment_id'] ?? '-',
                $i['kind'],
                $i['detail'],
            ], $issues),
        );

        $this->warn(sprintf('差異 %d 件（修復 %d 件）', count($issues), $repaired));

        if (! $repair) {
            $this->line('修復するには --repair を指定してください（派生状態のみ。Stripe への金銭操作は行いません）。');
        }
    }
}
