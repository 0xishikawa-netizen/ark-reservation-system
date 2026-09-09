<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Enum\ErrorCategory;

/** timeout / 一時的ネットワーク / 429 / 5xx 相当。retry 可能。 */
final class ProviderTransientException extends IntegrationException
{
    public function category(): ErrorCategory
    {
        return ErrorCategory::Retryable;
    }
}
