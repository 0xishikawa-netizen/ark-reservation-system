<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

/**
 * 外部側の予約参照。provider + externalId の単位で扱う（externalId 単独を global unique にしない）。
 */
final readonly class ProviderRef
{
    public function __construct(
        public string $provider,
        public string $externalReservationId,
        public ?string $externalUpdatedAt = null, // Y-m-d H:i:s（あれば）
    ) {}

    /** ログ / event 用のマスク済み ID（末尾 4 文字のみ）。 */
    public function maskedId(): string
    {
        return self::mask($this->externalReservationId);
    }

    public static function mask(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return strlen($id) <= 4 ? str_repeat('*', strlen($id)) : '****'.substr($id, -4);
    }
}
