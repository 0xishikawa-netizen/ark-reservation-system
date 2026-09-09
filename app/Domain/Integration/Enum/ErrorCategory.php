<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

enum ErrorCategory: string
{
    case Retryable = 'retryable';         // timeout / 一時的ネットワーク / 429 / 5xx 相当
    case NonRetryable = 'non_retryable';  // invalid request / mapping impossible
    case Unsupported = 'unsupported';     // Provider が capability を持たない
    case ConfigError = 'config_error';    // 認証設定不備 / 未設定
    case Ambiguous = 'ambiguous';         // 応答喪失。成功したかもしれない
}
