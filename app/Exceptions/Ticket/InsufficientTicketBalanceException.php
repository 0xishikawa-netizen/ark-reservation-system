<?php

declare(strict_types=1);

namespace App\Exceptions\Ticket;

final class InsufficientTicketBalanceException extends \RuntimeException
{
    public function __construct(
        string $message = '回数券の残数が不足しています',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
