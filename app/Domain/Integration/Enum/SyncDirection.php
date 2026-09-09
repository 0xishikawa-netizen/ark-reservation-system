<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum SyncDirection: string
{
    case Inbound = 'inbound';   // External → ARK
    case Outbound = 'outbound'; // ARK → External
}
