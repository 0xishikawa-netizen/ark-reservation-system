<?php

declare(strict_types=1);

namespace App\Domain\Integration\Provider;

use App\Domain\Integration\Contracts\AbstractReservationProvider;
use App\Domain\Integration\Dto\FetchWindow;
use App\Domain\Integration\Dto\OutboundReservationCommand;
use App\Domain\Integration\Dto\ProviderCapabilities;
use App\Domain\Integration\Dto\ProviderHealth;
use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ProviderCapability;

/**
 * Phase 9 の実 Provider 代替。挙動は MockReservationStore（container singleton）が決める。
 * ストアが空なら「予約 0 件 / create は決定的 fake ref」という安全既定。
 * test-only の分岐を Domain / 本番コードに置かないための境界。
 */
final class MockReservationProvider extends AbstractReservationProvider
{
    public function __construct(private readonly MockReservationStore $store) {}

    public function key(): string
    {
        return 'mock';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            ProviderCapability::ReadReservations,
            ProviderCapability::CreateReservations,
            ProviderCapability::UpdateReservations,
            ProviderCapability::CancelReservations,
            ProviderCapability::ReadAvailability,
            ProviderCapability::Polling,
        );
    }

    public function healthCheck(): ProviderHealth
    {
        return match ($this->store->health()) {
            'needs_attention' => ProviderHealth::needsAttention('Mock provider が要確認状態です。'),
            'unconfigured' => ProviderHealth::unconfigured(),
            default => ProviderHealth::ok('Mock provider は正常です。'),
        };
    }

    protected function doFetchReservations(FetchWindow $window): iterable
    {
        return $this->store->fetchCurrent();
    }

    protected function doCreateReservation(OutboundReservationCommand $command): ProviderRef
    {
        $externalId = $this->store->applyCreate($command->idempotencyKey, $command->reservationId);

        return new ProviderRef('mock', $externalId, now()->toDateTimeString());
    }

    protected function doUpdateReservation(string $externalId, OutboundReservationCommand $command): ProviderRef
    {
        $this->store->applyUpdate($externalId, $command->idempotencyKey, $command->reservationId);

        return new ProviderRef('mock', $externalId, now()->toDateTimeString());
    }

    protected function doCancelReservation(string $externalId, OutboundReservationCommand $command): void
    {
        $this->store->applyCancel($externalId, $command->idempotencyKey, $command->reservationId);
    }
}
