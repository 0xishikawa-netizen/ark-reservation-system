<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum ConflictStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
