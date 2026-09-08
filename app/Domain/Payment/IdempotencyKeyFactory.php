<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Payment;
use App\Models\PaymentRefund;
use RuntimeException;

final class IdempotencyKeyFactory
{
    public function paymentIntentCreate(Payment $payment): string
    {
        return $this->forPayment('payment_intent_create', $payment);
    }

    public function paymentIntentCapture(Payment $payment): string
    {
        return $this->forPayment('payment_intent_capture', $payment);
    }

    public function paymentIntentCancel(Payment $payment): string
    {
        return $this->forPayment('payment_intent_cancel', $payment);
    }

    public function refund(PaymentRefund $refund): string
    {
        $operationId = PaymentRefund::query()
            ->whereKey($refund->getKey())
            ->value('refund_operation_id');

        if (! is_string($operationId) || $operationId === '') {
            throw new RuntimeException('永続化済みの refund_operation_id が必要です。');
        }

        return $this->render('refund', '{refund_operation_id}', $operationId);
    }

    private function forPayment(string $templateName, Payment $payment): string
    {
        $operationId = Payment::query()
            ->whereKey($payment->getKey())
            ->value('payment_operation_id');

        if (! is_string($operationId) || $operationId === '') {
            throw new RuntimeException('永続化済みの payment_operation_id が必要です。');
        }

        return $this->render($templateName, '{payment_operation_id}', $operationId);
    }

    private function render(string $templateName, string $placeholder, string $operationId): string
    {
        $template = config("stripe.idempotency_key_templates.{$templateName}");

        if (! is_string($template) || ! str_contains($template, $placeholder)) {
            throw new RuntimeException("Stripe Idempotency-Key テンプレート [{$templateName}] が不正です。");
        }

        $key = str_replace($placeholder, $operationId, $template);

        if (str_contains($key, '{') || str_contains($key, '}')) {
            throw new RuntimeException("Stripe Idempotency-Key テンプレート [{$templateName}] に未解決の値があります。");
        }

        return $key;
    }
}
