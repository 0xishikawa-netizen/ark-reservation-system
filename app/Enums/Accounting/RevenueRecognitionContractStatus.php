<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum RevenueRecognitionContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case Voided = 'voided';
}
