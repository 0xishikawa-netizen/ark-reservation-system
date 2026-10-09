<?php

declare(strict_types=1);

namespace App\Exceptions\Membership;

final class InsufficientMembershipBalanceException extends \RuntimeException
{
    public function __construct(
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? __('messages.membership.insufficient_balance_exception'), 0, $previous);
    }
}
