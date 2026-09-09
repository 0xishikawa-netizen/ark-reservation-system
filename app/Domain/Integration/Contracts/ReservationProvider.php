<?php

declare(strict_types=1);

namespace App\Domain\Integration\Contracts;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Dto\FetchWindow;
use App\Domain\Integration\Dto\OutboundReservationCommand;
use App\Domain\Integration\Dto\ProviderCapabilities;
use App\Domain\Integration\Dto\ProviderHealth;
use App\Domain\Integration\Dto\ProviderRef;

/**
 * 外部予約サービスとの通信のみを担う契約。業務状態・invariant はここに置かない。
 *
 * すべての実装で全機能を強制しない。未対応操作は capabilities() で表現し、
 * 呼び出されたら UnsupportedProviderOperationException を投げる（AbstractReservationProvider が担保）。
 * raw provider 例外は IntegrationException 階層へ写して外へ出す。
 */
interface ReservationProvider
{
    public function key(): string;

    public function capabilities(): ProviderCapabilities;

    public function healthCheck(): ProviderHealth;

    /**
     * @return iterable<ExternalReservationData>
     */
    public function fetchReservations(FetchWindow $window): iterable;

    public function createReservation(OutboundReservationCommand $command): ProviderRef;

    public function updateReservation(string $externalId, OutboundReservationCommand $command): ProviderRef;

    public function cancelReservation(string $externalId, OutboundReservationCommand $command): void;
}
