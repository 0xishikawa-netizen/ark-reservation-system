<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum SyncEventStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case NoOp = 'no_op';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Conflict = 'conflict';
}
