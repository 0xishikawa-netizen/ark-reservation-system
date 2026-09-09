<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum ConflictType: string
{
    case TimeChangedBoth = 'TIME_CHANGED_BOTH';
    case StatusChangedBoth = 'STATUS_CHANGED_BOTH';
    case CancelVsUpdate = 'CANCEL_VS_UPDATE';
    case MissingMapping = 'MISSING_MAPPING';
    case DuplicateExternal = 'DUPLICATE_EXTERNAL';
    case UnsupportedState = 'UNSUPPORTED_STATE';
}
