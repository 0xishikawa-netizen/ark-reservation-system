<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityQuery;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityResult;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ExternalRef;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\GatewayCapabilities;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ReservationSnapshot;
use App\Modules\ExternalIntegration\Gateways\Reservation\ExternalReservationGateway;
use Carbon\CarbonImmutable;

class RecordingFakeReservationGateway implements ExternalReservationGateway
{
    /** @var list<AvailabilityQuery> */
    public array $availabilityQueries = [];

    /** @var list<ReservationSnapshot> */
    public array $pushedReservations = [];

    /** @var list<array{external_id: string, snapshot: ReservationSnapshot}> */
    public array $updatedReservations = [];

    /** @var list<array{external_id: string, reason: string|null}> */
    public array $canceledReservations = [];

    /** @var list<array{from: CarbonImmutable, to: CarbonImmutable}> */
    public array $pullRanges = [];

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            canFetchAvailability: true,
            canPush: true,
            canUpdate: true,
            canCancel: true,
            canPull: true,
            supportsWebhook: false,
        );
    }

    public function fetchAvailability(AvailabilityQuery $query): AvailabilityResult
    {
        $this->availabilityQueries[] = $query;

        return new AvailabilityResult([
            [
                'starts_at' => $query->from->toIso8601String(),
                'ends_at' => $query->to->toIso8601String(),
                'staff_id' => $query->staffId,
            ],
        ]);
    }

    public function pushReservation(ReservationSnapshot $snapshot): ExternalRef
    {
        $this->pushedReservations[] = $snapshot;

        return new ExternalRef('recording_fake', 'fake-'.count($this->pushedReservations));
    }

    public function updateExternalReservation(string $externalId, ReservationSnapshot $snapshot): ExternalRef
    {
        $this->updatedReservations[] = [
            'external_id' => $externalId,
            'snapshot' => $snapshot,
        ];

        return new ExternalRef('recording_fake', $externalId);
    }

    public function cancelExternalReservation(string $externalId, ?string $reason): void
    {
        $this->canceledReservations[] = [
            'external_id' => $externalId,
            'reason' => $reason,
        ];
    }

    public function pullReservations(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $this->pullRanges[] = [
            'from' => $from,
            'to' => $to,
        ];

        return [
            new ReservationSnapshot(
                localReservationId: null,
                customerName: 'テスト顧客',
                serviceName: 'テストサービス',
                staffName: null,
                startsAt: $from,
                endsAt: $to,
                source: 'recording_fake',
            ),
        ];
    }
}
