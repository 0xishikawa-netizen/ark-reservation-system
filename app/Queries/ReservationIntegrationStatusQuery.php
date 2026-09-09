<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Integration\Enum\ConflictStatus;
use App\Domain\Integration\Enum\OutboxStatus;
use App\Domain\Integration\ProviderResolver;
use App\Models\ReservationProviderSyncState;
use App\Models\ReservationSyncConflict;
use App\Models\ReservationSyncEvent;
use App\Models\ReservationSyncOutbox;

/**
 * Admin 用の外部連携ステータス集約。raw payload / credential / PII 全文は返さない。
 */
final class ReservationIntegrationStatusQuery
{
    public function __construct(private readonly ProviderResolver $resolver) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $activeKey = $this->safeActiveKey();

        $providers = [];
        foreach ($this->resolver->knownKeys() as $key) {
            $providers[] = $this->providerRow($key, $activeKey === $key);
        }

        return [
            'active_provider' => $activeKey,
            'providers' => $providers,
            'recent_events' => $this->recentEvents(),
        ];
    }

    /** @return array<string, mixed> */
    private function providerRow(string $key, bool $isActive): array
    {
        $state = ReservationProviderSyncState::query()->where('provider', $key)->first();
        $health = $this->health($key);

        $pendingOutbox = ReservationSyncOutbox::query()->where('provider', $key)
            ->where('status', OutboxStatus::Pending->value)->count();
        $failedOutbox = ReservationSyncOutbox::query()->where('provider', $key)
            ->whereIn('status', [OutboxStatus::Failed->value, OutboxStatus::NeedsAttention->value])->count();
        // 15 分以上 processing に固定された行（worker crash 等・F-04）。
        $stuckProcessing = ReservationSyncOutbox::query()->where('provider', $key)
            ->where('status', OutboxStatus::Processing->value)
            ->where('locked_at', '<', now()->subMinutes(15))->count();
        $openConflicts = ReservationSyncConflict::query()->where('provider', $key)
            ->where('status', ConflictStatus::Open->value)->count();

        $needsAttention = $failedOutbox + $openConflicts + $stuckProcessing;

        $status = match (true) {
            $isActive && $health === 'unconfigured' => '未設定',
            ! $isActive && $health === 'unconfigured' => '未設定',
            ! $isActive => '停止',
            $needsAttention > 0 || $health === 'needs_attention' => '要確認',
            default => '正常',
        };

        return [
            'key' => $key,
            'label' => $this->label($key),
            'is_active' => $isActive,
            'status' => $status,
            'health' => $health,
            'last_inbound_at' => $state?->last_inbound_at?->toDateTimeString(),
            'last_outbound_at' => $state?->last_outbound_at?->toDateTimeString(),
            'last_reconcile_at' => $state?->last_reconcile_at?->toDateTimeString(),
            'pending_outbox' => $pendingOutbox,
            'failed_outbox' => $failedOutbox,
            'stuck_processing' => $stuckProcessing,
            'open_conflicts' => $openConflicts,
            'needs_attention' => $needsAttention,
            'actionable' => $isActive ? $this->actionableOutbox($key) : [],
        ];
    }

    /**
     * 手動 retry できる outbox 行（failed / needs_attention）。masked 情報のみ。
     *
     * @return list<array<string, mixed>>
     */
    private function actionableOutbox(string $key, int $limit = 20): array
    {
        return ReservationSyncOutbox::query()
            ->where('provider', $key)
            ->whereIn('status', [OutboxStatus::Failed->value, OutboxStatus::NeedsAttention->value])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'reservation_id', 'operation', 'status', 'attempts', 'last_error_category', 'last_error_code', 'updated_at'])
            ->map(fn (ReservationSyncOutbox $o): array => [
                'id' => (int) $o->id,
                'reservation_id' => (int) $o->reservation_id,
                'operation' => $o->operation->value,
                'status' => $o->status->value,
                'attempts' => (int) $o->attempts,
                'error' => $o->last_error_category === null ? null : "{$o->last_error_category} / {$o->last_error_code}",
                'at' => $o->updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentEvents(int $limit = 40): array
    {
        return ReservationSyncEvent::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'provider', 'direction', 'operation', 'status',
                'external_reservation_id_masked', 'error_category', 'safe_error_code',
                'attempt', 'created_at',
            ])
            ->map(fn (ReservationSyncEvent $e): array => [
                'id' => (int) $e->id,
                'provider' => $e->provider,
                'direction' => $e->direction->value,
                'operation' => $e->operation->value,
                'status' => $e->status->value,
                'external_id' => $e->external_reservation_id_masked,
                'error_category' => $e->error_category,
                'error_code' => $e->safe_error_code,
                'attempt' => (int) $e->attempt,
                'at' => $e->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function safeActiveKey(): ?string
    {
        try {
            return $this->resolver->activeKey();
        } catch (\Throwable) {
            return null;
        }
    }

    private function health(string $key): string
    {
        try {
            return $this->resolver->resolve($key)->healthCheck()->state;
        } catch (\Throwable) {
            return 'unconfigured';
        }
    }

    private function label(string $key): string
    {
        return match ($key) {
            'mock' => 'Mock（テスト用）',
            'peak_manager' => 'Peak Manager',
            'salon_board' => 'SALON BOARD',
            default => $key,
        };
    }
}
