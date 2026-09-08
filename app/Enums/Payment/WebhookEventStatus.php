<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
