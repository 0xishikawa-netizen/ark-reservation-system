<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PendingPayment = 'pending_payment';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Voided = 'voided';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Failed = 'failed';
}
