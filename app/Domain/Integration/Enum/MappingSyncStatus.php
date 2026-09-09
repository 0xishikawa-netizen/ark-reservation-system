<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum MappingSyncStatus: string
{
    case InSync = 'in_sync';
    case Drift = 'drift';
    case Conflict = 'conflict';
    case Stale = 'stale';
}
