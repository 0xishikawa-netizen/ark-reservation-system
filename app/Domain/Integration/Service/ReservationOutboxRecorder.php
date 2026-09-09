<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Enum\ProviderCapability;
use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\IntegrationContext;
use App\Domain\Integration\ProviderResolver;
use App\Enums\Reservation\ReservationSource;
use App\Models\Reservation;
use App\Models\ReservationSyncOutbox;
use Illuminate\Support\Str;

/**
 * ARK → 外部同期の Outbox 行を「予約更新と同一 transaction」で作成する。
 *
 * - 有効 Provider が無い / Outbox 無効 / Provider が該当 capability を持たない → 何もしない（no-op）。
 * - Domain（ReservationService）は operation を渡すだけ。provider 名分岐はここに閉じる。
 * - idempotency key = rsv-out:{op}:{reservation_id}:{seq}。同じ論理操作の retry は同じ行/キー。
 *   別の論理操作（2 回目の reschedule 等）は seq が進み別行になる。
 */
final class ReservationOutboxRecorder
{
    private const CAPABILITY = [
        'create' => ProviderCapability::CreateReservations,
        'update' => ProviderCapability::UpdateReservations,
        'cancel' => ProviderCapability::CancelReservations,
    ];

    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly IntegrationContext $context,
    ) {}

    public function record(Reservation $reservation, SyncOperation $operation): ?ReservationSyncOutbox
    {
        if (! (bool) config('reservation_integration.outbox.enabled', true)) {
            return null;
        }

        // inbound 適用中の変更は同じ Provider へ折り返さない（因果ループ防止・F-06）。
        if ($this->context->isApplyingInbound()) {
            return null;
        }

        // 外部由来の予約は ARK から折り返し送信しない（inbound apply 外の経路でも echo を防ぐ・F-06）。
        if ($reservation->source === ReservationSource::External) {
            return null;
        }

        if (! $this->resolver->hasActive()) {
            return null;
        }

        $provider = $this->resolver->active();
        $capability = self::CAPABILITY[$operation->value] ?? null;

        if ($capability === null || ! $provider->capabilities()->has($capability)) {
            return null;
        }

        $seq = ReservationSyncOutbox::query()
            ->where('reservation_id', $reservation->id)
            ->where('operation', $operation->value)
            ->count() + 1;

        return ReservationSyncOutbox::query()->create([
            'provider' => $provider->key(),
            'reservation_id' => $reservation->id,
            'operation' => $operation,
            'idempotency_key' => "rsv-out:{$operation->value}:{$reservation->id}:{$seq}",
            'payload_json' => [
                'starts_at' => $reservation->starts_at?->toIso8601String(),
                'ends_at' => $reservation->ends_at?->toIso8601String(),
                'status' => $reservation->status?->value,
                'service_id' => $reservation->service_id,
                'staff_id' => $reservation->staff_id,
            ],
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
