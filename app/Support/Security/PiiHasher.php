<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

final class PiiHasher
{
    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $normalized = preg_replace('/\D+/u', '', $raw);

        return $normalized === null || $normalized === '' ? null : $normalized;
    }

    public static function phoneHmac(?string $raw): ?string
    {
        $normalized = self::normalizePhone($raw);

        return $normalized === null
            ? null
            : hash_hmac('sha256', $normalized, self::key());
    }

    private static function key(): string
    {
        $key = config('security.pii_lookup_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('PII_LOOKUP_KEY が未設定です。config/security.php を確認してください。');
        }

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('PII_LOOKUP_KEY の base64 値が不正です。config/security.php を確認してください。');
        }

        return $decoded;
    }
}
