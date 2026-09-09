<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Enum\ErrorCategory;

final class ProviderConfigurationException extends IntegrationException
{
    public function category(): ErrorCategory
    {
        return ErrorCategory::ConfigError;
    }
}
