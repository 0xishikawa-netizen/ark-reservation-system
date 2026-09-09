<?php

declare(strict_types=1);

namespace App\Domain\Integration\Contracts;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Dto\FetchWindow;
use App\Domain\Integration\Dto\OutboundReservationCommand;
use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ProviderCapability;

/**
 * capability 検査を一元化する基底。各 Provider は do*() だけを実装する。
 * これにより「未対応 capability を安全に拒否」が Domain 側の分岐なしで担保される。
 */
abstract class AbstractReservationProvider implements ReservationProvider
{
    public function fetchReservations(FetchWindow $window): iterable
    {
        $this->capabilities()->assert(ProviderCapability::ReadReservations);

        return $this->doFetchReservations($window);
    }

    public function createReservation(OutboundReservationCommand $command): ProviderRef
    {
        $this->capabilities()->assert(ProviderCapability::CreateReservations);

        return $this->doCreateReservation($command);
    }

    public function updateReservation(string $externalId, OutboundReservationCommand $command): ProviderRef
    {
        $this->capabilities()->assert(ProviderCapability::UpdateReservations);

        return $this->doUpdateReservation($externalId, $command);
    }

    public function cancelReservation(string $externalId, OutboundReservationCommand $command): void
    {
        $this->capabilities()->assert(ProviderCapability::CancelReservations);

        $this->doCancelReservation($externalId, $command);
    }

    /** @return iterable<ExternalReservationData> */
    abstract protected function doFetchReservations(FetchWindow $window): iterable;

    abstract protected function doCreateReservation(OutboundReservationCommand $command): ProviderRef;

    abstract protected function doUpdateReservation(string $externalId, OutboundReservationCommand $command): ProviderRef;

    abstract protected function doCancelReservation(string $externalId, OutboundReservationCommand $command): void;
}
