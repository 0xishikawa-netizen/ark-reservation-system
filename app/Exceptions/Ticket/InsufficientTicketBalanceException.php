<?php

declare(strict_types=1);

namespace App\Exceptions\Ticket;

final class InsufficientTicketBalanceException extends \RuntimeException
{
    public function __construct(
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? __('messages.ticket.insufficient_balance_exception'), 0, $previous);
    }
}
