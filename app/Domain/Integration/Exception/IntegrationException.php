<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Enum\ErrorCategory;
use RuntimeException;

/**
 * 外部連携の基底例外。raw provider 例外はこの階層へ写してから外へ出す
 * （生の外部エラーを frontend / audit / log へ流さない）。
 */
abstract class IntegrationException extends RuntimeException
{
    abstract public function category(): ErrorCategory;

    /** ログ/イベントに残して安全な短いコード（PII/secret を含めない）。 */
    public function safeCode(): string
    {
        return class_basename(static::class);
    }
}
