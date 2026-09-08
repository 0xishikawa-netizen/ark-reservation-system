<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway;

use App\Domain\Payment\Gateway\Dto\CreatePaymentIntentCommand;
use App\Domain\Payment\Gateway\Dto\CreateRefundCommand;
use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\Dto\RefundResult;

interface StripeGateway
{
    public function createPaymentIntent(CreatePaymentIntentCommand $command): PaymentIntentResult;

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult;

    public function capturePaymentIntent(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult;

    public function cancelPaymentIntent(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult;

    public function createRefund(CreateRefundCommand $command): RefundResult;

    public function retrieveEvent(string $eventId): mixed;
}
