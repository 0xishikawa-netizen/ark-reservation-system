<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Payment\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 返金は機微操作（PLAN §12）。manager 以上 + refund.execute + password.confirm はルート側で強制する。
 * ここでは金額と理由の妥当性だけを検証する。
 */
class RefundPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('refund.execute') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            // 理由必須。監査に残る。
            'reason' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payment = $this->route('payment');

            if (! $payment instanceof Payment) {
                return;
            }

            if (! in_array($payment->status, [
                PaymentStatus::Succeeded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                $validator->errors()->add(
                    'payment',
                    __('messages.payment.only_captured_refundable_hint'),
                );

                return;
            }

            $remaining = (int) $payment->amount - (int) $payment->refunded_amount;

            if ((int) $this->input('amount') > $remaining) {
                $validator->errors()->add(
                    'amount',
                    __('messages.payment.refundable_amount', ['amount' => $remaining]),
                );
            }
        });
    }
}
