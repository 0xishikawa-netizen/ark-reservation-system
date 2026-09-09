<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Enum\ErrorCategory;

/** 正規化した外部予約が業務上取り込めない（必須項目欠落・時刻不正など）。 */
final class InvalidExternalReservationException extends IntegrationException
{
    public function category(): ErrorCategory
    {
        return ErrorCategory::NonRetryable;
    }
}
