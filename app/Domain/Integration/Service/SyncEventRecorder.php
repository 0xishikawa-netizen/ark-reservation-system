<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ErrorCategory;
use App\Domain\Integration\Enum\SyncDirection;
use App\Domain\Integration\Enum\SyncEventStatus;
use App\Domain\Integration\Enum\SyncOperation;
use App\Models\ReservationSyncEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 追記専用の sync event を安全に記録する（PII / secret / raw payload を残さない）。
 */
final class SyncEventRecorder
{
    public function record(
        string $provider,
        SyncDirection $direction,
        SyncOperation $operation,
        SyncEventStatus $status,
        ?int $reservationId = null,
        ?string $externalId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        int $attempt = 1,
        ?ErrorCategory $errorCategory = null,
        ?string $safeErrorCode = null,
        ?CarbonImmutable $startedAt = null,
    ): ReservationSyncEvent {
        $now = CarbonImmutable::now();
        $startedAt ??= $now;
        $completed = in_array($status, [
            SyncEventStatus::Succeeded, SyncEventStatus::NoOp, SyncEventStatus::Skipped,
            SyncEventStatus::Failed, SyncEventStatus::Conflict,
        ], true);

        return ReservationSyncEvent::query()->create([
            'provider' => $provider,
            'direction' => $direction,
            'operation' => $operation,
            'reservation_id' => $reservationId,
            'external_reservation_id_masked' => ProviderRef::mask($externalId),
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'attempt' => $attempt,
            'error_category' => $errorCategory?->value,
            'safe_error_code' => $safeErrorCode === null ? null : substr($safeErrorCode, 0, 80),
            'started_at' => $startedAt,
            'completed_at' => $completed ? $now : null,
            'created_at' => $now,
        ]);
    }
}
