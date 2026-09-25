<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum RevenueContractKind: string
{
    case Ticket = 'ticket';
    case Membership = 'membership';
}
