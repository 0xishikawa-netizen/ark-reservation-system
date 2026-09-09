<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Enum\ErrorCategory;

/** invalid request / mapping impossible 等。retry しても無駄。 */
final class ProviderPermanentException extends IntegrationException
{
    public function category(): ErrorCategory
    {
        return ErrorCategory::NonRetryable;
    }
}
