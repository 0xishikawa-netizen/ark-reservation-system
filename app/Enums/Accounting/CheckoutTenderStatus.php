<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum CheckoutTenderStatus: string
{
    case Received = 'received';
    case Voided = 'voided';
}
