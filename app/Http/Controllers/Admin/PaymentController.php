<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Payment\PaymentService;
use App\Domain\Payment\ReservationCheckoutSaga;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Models\Payment;
use App\Queries\CustomerLookupQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 運用に必要な最小限の決済管理（Stripe Dashboard の代替は作らない）。
 */
class PaymentController extends Controller
{
    public function index(Request $request, CustomerLookupQuery $customerLookup): Response
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,user_id'],
        ]);
        $customerId = isset($validated['customer_id']) ? (int) $validated['customer_id'] : null;

        $payments = Payment::query()
            ->with(['reservation:id,starts_at,status', 'customer.user:id,name'])
            ->when($request->string('status')->toString() !== '', function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->when($request->boolean('needs_attention'), function ($query): void {
                $query->where('needs_attention', true);
            })
            ->when($customerId !== null, function ($query) use ($customerId): void {
                $query->where('customer_id', $customerId);
            })
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Payment $payment): array => $this->summary($payment));

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'needs_attention' => $request->boolean('needs_attention'),
                'customer_id' => $customerId,
            ],
            'filtered_customer' => $customerId === null ? null : $customerLookup->find($customerId),
            'statuses' => array_map(
                static fn (PaymentStatus $status): string => $status->value,
                PaymentStatus::cases(),
            ),
            'attention_count' => Payment::query()->where('needs_attention', true)->count(),
        ]);
    }

    public function show(Payment $payment): Response
    {
        $payment->loadMissing([
            'reservation:id,starts_at,status,payment_status',
            'customer.user:id,name',
            'refunds.creator:id,name',
        ]);

        return Inertia::render('Admin/Payments/Show', [
            'payment' => [
                ...$this->summary($payment),
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'stripe_charge_id' => $payment->stripe_charge_id,
                'authorized_at' => $payment->authorized_at?->format('Y-m-d H:i:s'),
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
                'voided_at' => $payment->voided_at?->format('Y-m-d H:i:s'),
                'last_synced_at' => $payment->last_synced_at?->format('Y-m-d H:i:s'),
                'failure_code' => $payment->failure_code,
                'failure_message' => $payment->failure_message,
                'capture_method' => $payment->capture_method,
                'refundable_amount' => $this->refundableAmount($payment),
                'refunds' => $payment->refunds->map(fn ($refund): array => [
                    'id' => (int) $refund->id,
                    'amount' => (int) $refund->amount,
                    'status' => $refund->status->value,
                    'reason' => $refund->reason,
                    'created_by' => $refund->creator?->name,
                    'created_at' => $refund->created_at?->format('Y-m-d H:i:s'),
                    'stripe_refund_id' => $refund->stripe_refund_id,
                ])->all(),
            ],
            'can' => [
                'refund' => $this->userCanRefund(),
            ],
        ]);
    }

    /**
     * 返金実行。ルート側で `can:refund.execute` + `password.confirm` を要求する。
     *
     * authorized（未 capture）と succeeded（capture 済み）の使い分けは
     * PaymentService に集約されており、ここでは判断しない。
     */
    public function refund(
        RefundPaymentRequest $request,
        Payment $payment,
        PaymentService $payments,
    ): RedirectResponse {
        $validated = $request->validated();
        $amount = (int) $validated['amount'];
        $reason = (string) $validated['reason'];

        try {
            $payments->refund($payment, $amount, $reason, $request->user());
        } catch (PaymentGatewayDeclinedException) {
            return back()->with('error', __('messages.payment.refund_declined'));
        } catch (PaymentGatewayException) {
            return back()->with(
                'error',
                '返金結果を確認できませんでした。要対応として記録しました。payments:reconcile で確認してください。',
            );
        }

        return back()->with('success', __('messages.payment.refunded'));
    }

    /**
     * Stripe の現在状態を取り込み直す（孤立決済の手動復旧）。
     */
    public function sync(
        Payment $payment,
        ReservationCheckoutSaga $saga,
    ): RedirectResponse {
        try {
            $saga->syncAndAdvance($payment);
        } catch (PaymentGatewayException) {
            return back()->with('error', __('messages.payment.sync_failed'));
        }

        return back()->with('success', __('messages.payment.synced'));
    }

    /** @return array<string, mixed> */
    private function summary(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'reservation_id' => $payment->reservation_id === null ? null : (int) $payment->reservation_id,
            'reservation_starts_at' => $payment->reservation?->starts_at?->format('Y-m-d H:i'),
            'reservation_status' => $payment->reservation?->status->value,
            'customer_name' => $payment->customer?->user?->name,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => $payment->status->value,
            'refunded_amount' => (int) $payment->refunded_amount,
            'needs_attention' => (bool) $payment->needs_attention,
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function refundableAmount(Payment $payment): int
    {
        if (! in_array($payment->status, [
            PaymentStatus::Succeeded,
            PaymentStatus::PartiallyRefunded,
        ], true)) {
            return 0;
        }

        return max(0, (int) $payment->amount - (int) $payment->refunded_amount);
    }

    private function userCanRefund(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('refund.execute');
    }
}
