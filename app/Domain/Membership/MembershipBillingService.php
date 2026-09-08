<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Models\Membership;
use App\Models\Payment;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Membership invoice の会計反映と期首 GRANT / grace・paused の適用。
 *
 * - invoice は Phase 5 単発決済とは別種（PaymentKind::MembershipInvoice / capture_method=automatic）。
 * - payment_operation_id は決定的（`inv:{stripe_invoice_id}`）。UNIQUE 冪等で二重記録なし。
 * - 期首 GRANT は 1 期 1 回（`grant:{membership_id}:{period_start}`）。webhook duplicate / retry / 順序逆転で二重にならない。
 * - 返金を受けても usage GRANT を自動取消ししない（業務仕様。調整は管理者の明示 ADJUST）。
 */
final class MembershipBillingService
{
    public function __construct(
        private readonly MembershipSubscriptionService $subscriptions,
        private readonly MembershipLedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * invoice.paid: payment 記録 → subscription 現在値へ同期（grace 解除・active 化）→ 当期 GRANT（1 回のみ）。
     *
     * @param  array<string, mixed>  $invoice  Stripe invoice object（data.object）
     */
    public function recordInvoicePaid(Membership $membership, array $invoice): void
    {
        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;

        if ($invoiceId === null) {
            return;
        }

        $this->recordInvoicePayment($membership, $invoice, 'succeeded');

        // [HTTP は transaction の外] Stripe の現在状態へ前進のみ同期（active 化 / grace 解除 / 期更新）。
        $this->subscriptions->syncFromStripe($membership);

        $membership->refresh();

        // 当期 GRANT（1 期 1 回）。current_period_start が無ければ何もしない。
        if ($membership->current_period_start !== null && $membership->plan !== null) {
            $this->ledger->grant(
                membership: $membership,
                periodStart: $membership->current_period_start->toDateString(),
                count: (int) $membership->plan->usage_count_per_period,
                reason: "invoice {$invoiceId}",
            );
        }
    }

    /**
     * invoice.payment_failed / payment_action_required: 即 paused にしない。
     * Stripe の現在状態（past_due→grace / unpaid→paused）と grace policy を適用する。
     *
     * @param  array<string, mixed>  $invoice
     */
    public function handlePaymentIssue(Membership $membership, array $invoice): void
    {
        $this->recordInvoicePayment($membership, $invoice, 'failed');

        // grace / paused の判定は「イベントの中身」ではなく Stripe の現在 subscription で行う。
        $this->subscriptions->syncFromStripe($membership);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function recordInvoicePayment(Membership $membership, array $invoice, string $status): void
    {
        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;

        if ($invoiceId === null) {
            return;
        }

        $operationId = "inv:{$invoiceId}";

        $existing = Payment::query()->where('payment_operation_id', $operationId)->first();

        // amount_paid は未払い invoice でも 0 が「入っている」ため ?? では amount_due に落ちない。
        // 実際に支払われた額を優先し、0 なら請求額（amount_due）を金額として記録する。
        $amount = (int) ($invoice['amount_paid'] ?? 0);
        if ($amount <= 0) {
            $amount = (int) ($invoice['amount_due'] ?? 0);
        }
        $chargeId = is_string($invoice['charge'] ?? null) ? $invoice['charge'] : null;
        $paymentIntentId = is_string($invoice['payment_intent'] ?? null) ? $invoice['payment_intent'] : null;

        if ($existing !== null) {
            // 既存（duplicate webhook / 順序逆転）。succeeded を failed で上書きしない（前進のみ）。
            if ($status === 'succeeded' && $existing->status !== 'succeeded') {
                DB::transaction(function () use ($existing, $amount, $chargeId): void {
                    $locked = Payment::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();
                    $locked->forceFill([
                        'status' => 'succeeded',
                        'amount' => $amount > 0 ? $amount : $locked->amount,
                        'stripe_charge_id' => $chargeId ?? $locked->stripe_charge_id,
                        'paid_at' => now(),
                        'last_synced_at' => now(),
                    ])->save();
                });
            }

            return;
        }

        try {
            DB::transaction(function () use ($membership, $operationId, $amount, $status, $chargeId, $paymentIntentId): void {
                (new Payment)->forceFill([
                    'customer_id' => $membership->customer_id,
                    'reservation_id' => null,
                    'kind' => 'membership_invoice',
                    'provider' => 'stripe',
                    'payment_operation_id' => $operationId,
                    'amount' => $amount,
                    'currency' => 'jpy',
                    'status' => $status,
                    'capture_method' => 'automatic',
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'stripe_charge_id' => $chargeId,
                    'paid_at' => $status === 'succeeded' ? now() : null,
                    'last_synced_at' => now(),
                ])->save();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            // 同一 invoice の並行 webhook が僅差で INSERT 競合した（payment_operation_id UNIQUE）。
            // 既存行へ収束させ、succeeded なら前進のみ反映する（F-08）。
            $raced = Payment::query()->where('payment_operation_id', $operationId)->first();

            if ($raced !== null && $status === 'succeeded' && $raced->status !== 'succeeded') {
                DB::transaction(function () use ($raced, $amount, $chargeId): void {
                    $locked = Payment::query()->whereKey($raced->getKey())->lockForUpdate()->firstOrFail();
                    $locked->forceFill([
                        'status' => 'succeeded',
                        'amount' => $amount > 0 ? $amount : $locked->amount,
                        'stripe_charge_id' => $chargeId ?? $locked->stripe_charge_id,
                        'paid_at' => now(),
                        'last_synced_at' => now(),
                    ])->save();
                });
            }

            return;
        }

        $this->auditLogger->log(
            $status === 'succeeded' ? 'payment.captured' : 'payment.failed',
            $membership,
            "利用権 invoice {$operationId} {$status}（{$amount} 円）",
            null,
        );
    }
}
