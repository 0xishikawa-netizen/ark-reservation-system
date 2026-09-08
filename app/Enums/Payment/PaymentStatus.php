<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Succeeded = 'succeeded';
    case Voided = 'voided';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
