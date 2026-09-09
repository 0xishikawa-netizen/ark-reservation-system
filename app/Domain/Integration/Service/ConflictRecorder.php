<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ConflictStatus;
use App\Domain\Integration\Enum\ConflictType;
use App\Models\ReservationSyncConflict;
use Illuminate\Database\QueryException;

/**
 * 競合を needs_attention として記録する。PII は残さず fingerprint のみ。
 * 同じ (provider, reservation_id or ref_hash, conflict_type) の open が既にあれば detected_at を更新する。
 * 並行作成は 23000 catch で既存へ収束（F-16）。
 */
final class ConflictRecorder
{
    public function open(
        string $provider,
        ?int $reservationId,
        ConflictType $type,
        ?string $arkFingerprint,
        ?string $externalFingerprint,
        ?string $externalIdOrHash,
        ?string $externalRefHash = null,
    ): ReservationSyncConflict {
        // externalIdOrHash が生 ID なら mask、そうでなければそのまま（呼び出し互換のため）。
        $masked = $externalIdOrHash !== null && ! str_starts_with((string) $externalIdOrHash, '****')
            ? ProviderRef::mask($externalIdOrHash)
            : $externalIdOrHash;

        $query = ReservationSyncConflict::query()
            ->where('provider', $provider)
            ->where('conflict_type', $type->value)
            ->where('status', ConflictStatus::Open->value);

        if ($reservationId !== null) {
            $query->where('reservation_id', $reservationId);
        } elseif ($externalRefHash !== null) {
            $query->whereNull('reservation_id')->where('external_ref_hash', $externalRefHash);
        } else {
            $query->whereNull('reservation_id')->where('external_reservation_id_masked', $masked);
        }

        $existing = $query->first();

        if ($existing !== null) {
            $existing->forceFill([
                'detected_at' => now(),
                'ark_fingerprint' => $arkFingerprint ?? $existing->ark_fingerprint,
                'external_fingerprint' => $externalFingerprint ?? $existing->external_fingerprint,
            ])->save();

            return $existing;
        }

        try {
            return ReservationSyncConflict::query()->create([
                'provider' => $provider,
                'reservation_id' => $reservationId,
                'external_reservation_id_masked' => $masked,
                'external_ref_hash' => $externalRefHash,
                'conflict_type' => $type,
                'ark_fingerprint' => $arkFingerprint,
                'external_fingerprint' => $externalFingerprint,
                'detected_at' => now(),
                'status' => ConflictStatus::Open,
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return $query->first() ?? throw $e;
        }
    }
}
