<?php

declare(strict_types=1);

namespace App\Domain\Integration\Provider;

use App\Domain\Integration\Contracts\AbstractReservationProvider;
use App\Domain\Integration\Dto\FetchWindow;
use App\Domain\Integration\Dto\OutboundReservationCommand;
use App\Domain\Integration\Dto\ProviderCapabilities;
use App\Domain\Integration\Dto\ProviderHealth;
use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Exception\ProviderConfigurationException;

/**
 * Phase 9 では skeleton のみ。実 API（URL / endpoint / auth / JSON / rate limit / webhook）は
 * ARK 独自システムから利用可能かが未確定のため一切推測しない。
 * 常に「未設定/Phase 10 待ち」として安全に停止する（fail-closed）。
 */
final class SalonBoardReservationProvider extends AbstractReservationProvider
{
    public function key(): string
    {
        return 'salon_board';
    }

    public function capabilities(): ProviderCapabilities
    {
        // 実 API 仕様が確定するまで何も対応しない。
        return ProviderCapabilities::none();
    }

    public function healthCheck(): ProviderHealth
    {
        return ProviderHealth::unconfigured('SALON BOARD の実 API 仕様が未確定です（Phase 10）。');
    }

    protected function doFetchReservations(FetchWindow $window): iterable
    {
        throw $this->notImplemented();
    }

    protected function doCreateReservation(OutboundReservationCommand $command): ProviderRef
    {
        throw $this->notImplemented();
    }

    protected function doUpdateReservation(string $externalId, OutboundReservationCommand $command): ProviderRef
    {
        throw $this->notImplemented();
    }

    protected function doCancelReservation(string $externalId, OutboundReservationCommand $command): void
    {
        throw $this->notImplemented();
    }

    private function notImplemented(): ProviderConfigurationException
    {
        return new ProviderConfigurationException('SALON BOARD 連携は Phase 10 で実装予定です。');
    }
}
