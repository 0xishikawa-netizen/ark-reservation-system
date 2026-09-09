<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum SyncOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Cancel = 'cancel';
    case Fetch = 'fetch';
    case Reconcile = 'reconcile';
}
