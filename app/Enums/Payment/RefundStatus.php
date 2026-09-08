<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
