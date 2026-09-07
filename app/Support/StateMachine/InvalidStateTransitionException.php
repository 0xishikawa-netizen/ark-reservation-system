<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use DomainException;

class InvalidStateTransitionException extends DomainException
{
    public function __construct(string $machine, string $from, string $to)
    {
        parent::__construct(
            "状態遷移 [{$from}] -> [{$to}] は State Machine [{$machine}] では許可されていません。",
        );
    }
}
