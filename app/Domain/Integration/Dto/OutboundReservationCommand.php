<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

use App\Domain\Integration\Enum\SyncOperation;
use Carbon\CarbonImmutable;

/**
 * ARK → 外部へ送る 1 操作分の正規化コマンド。
 * PII は含めない（顧客名などの受け渡しが必要になったら Phase 10 で mask 方針を確定）。
 */
final readonly class OutboundReservationCommand
{
    public function __construct(
        public SyncOperation $operation,
        public int $reservationId,
        public string $idempotencyKey,
        public string $correlationId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $arkStatus,
        public ?string $serviceRef = null,
        public ?string $staffRef = null,
        public ?string $cancelReason = null,
    ) {}
}
