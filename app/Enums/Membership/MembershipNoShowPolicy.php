<?php

declare(strict_types=1);

namespace App\Enums\Membership;

enum MembershipNoShowPolicy: string
{
    case Consume = 'consume';
    case Restore = 'restore';
}
