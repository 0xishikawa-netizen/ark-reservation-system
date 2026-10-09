<?php

declare(strict_types=1);

namespace App\Exceptions\Payment;

use Throwable;

class PaymentGatewayDeclinedException extends PaymentGatewayException
{
    public readonly string $gatewayCode;

    public readonly string $declineCode;

    public function __construct(
        string $code,
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        $this->gatewayCode = $code;
        $this->declineCode = $code;

        parent::__construct($message ?? __('messages.payment.card_payment_declined'), 0, $previous);
    }

    public function getGatewayCode(): string
    {
        return $this->gatewayCode;
    }

    public function code(): string
    {
        return $this->gatewayCode;
    }
}
