<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum CheckoutStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Voided = 'voided';
}
