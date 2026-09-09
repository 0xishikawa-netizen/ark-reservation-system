<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

/**
 * Inbound の 1 件の判定結果（監査/テスト用）。
 */
final readonly class InboundDecision
{
    public const NO_OP = 'no_op';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const CANCEL = 'cancel';

    public const CONFLICT = 'conflict';

    public const SKIPPED_STALE = 'skipped_stale';

    public const SKIPPED_UNSUPPORTED = 'skipped_unsupported';

    public function __construct(
        public string $action,
        public ?int $reservationId = null,
        public ?string $conflictType = null,
        public ?string $note = null,
    ) {}
}
