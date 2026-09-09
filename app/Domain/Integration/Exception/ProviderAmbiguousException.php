<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Enum\ErrorCategory;

/**
 * リクエストは送ったが応答が確認できない（timeout 後にレスポンスだけ消失など）。
 * 成功したかもしれないので確定失敗にしない。同一 idempotency key で retry / reconcile で収束。
 */
final class ProviderAmbiguousException extends IntegrationException
{
    public function category(): ErrorCategory
    {
        return ErrorCategory::Ambiguous;
    }
}
