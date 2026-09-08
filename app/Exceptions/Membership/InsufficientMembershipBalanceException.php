<?php

declare(strict_types=1);

namespace App\Exceptions\Membership;

final class InsufficientMembershipBalanceException extends \RuntimeException
{
    public function __construct(
        string $message = '当期の利用可能回数が不足しています',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
