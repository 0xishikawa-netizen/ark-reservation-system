<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Exceptions;

use RuntimeException;

class GatewayNotImplementedException extends RuntimeException
{
    // Phase 1 で未実装の外部 Gateway が選択されたことを通知する。
}
