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
        string $message = 'カード決済が承認されませんでした。',
        ?Throwable $previous = null,
    ) {
        $this->gatewayCode = $code;
        $this->declineCode = $code;

        parent::__construct($message, 0, $previous);
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
