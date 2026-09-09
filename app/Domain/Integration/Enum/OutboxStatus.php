<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NeedsAttention = 'needs_attention';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Skipped], true);
    }
}
