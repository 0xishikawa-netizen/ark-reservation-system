<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Exceptions;

use RuntimeException;

class UnsupportedOperationException extends RuntimeException
{
    // Gateway が対応していない操作を fail-fast で通知する。
}
