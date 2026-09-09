<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Dto\OutboundReservationCommand;
use App\Domain\Integration\Dto\ProviderCapabilities;
use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ProviderCapability;
use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\Exception\ProviderConfigurationException;
use App\Domain\Integration\Exception\UnsupportedProviderOperationException;
use App\Domain\Integration\Provider\MockReservationProvider;
use App\Domain\Integration\Provider\PeakManagerReservationProvider;
use App\Domain\Integration\ProviderRegistry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProviderContractTest extends TestCase
{
    public function test_capabilities_assert_throws_for_missing_capability(): void
    {
        $caps = new ProviderCapabilities(ProviderCapability::ReadReservations);

        $this->assertTrue($caps->has(ProviderCapability::ReadReservations));
        $this->assertFalse($caps->has(ProviderCapability::CreateReservations));

        $this->expectException(UnsupportedProviderOperationException::class);
        $caps->assert(ProviderCapability::CreateReservations);
    }

    public function test_skeleton_provider_fails_closed_on_every_operation(): void
    {
        $provider = new PeakManagerReservationProvider;
        $this->assertSame([], $provider->capabilities()->toArray());
        $this->assertSame('unconfigured', $provider->healthCheck()->state);

        $command = new OutboundReservationCommand(
            SyncOperation::Create, 1, 'k', 'c',
            CarbonImmutable::parse('2026-10-01 10:00'), CarbonImmutable::parse('2026-10-01 11:00'), 'confirmed',
        );

        // capability が無いので UnsupportedProviderOperationException（config を推測しない）。
        $this->expectException(UnsupportedProviderOperationException::class);
        $provider->createReservation($command);
    }

    public function test_registry_and_resolver_fail_closed_on_unknown_key(): void
    {
        $registry = new ProviderRegistry(['mock' => ['class' => MockReservationProvider::class]]);
        $this->assertTrue($registry->has('mock'));
        $this->assertFalse($registry->has('bogus'));

        $this->expectException(ProviderConfigurationException::class);
        $registry->classFor('bogus');
    }

    public function test_provider_ref_masking(): void
    {
        $this->assertNull(ProviderRef::mask(null));
        $this->assertSame('****3456', ProviderRef::mask('ext-0000123456'));
        $this->assertSame('***', ProviderRef::mask('abc'));
    }

    public function test_external_reservation_data_fingerprint_is_stable_and_order_independent(): void
    {
        $a = new ExternalReservationData('mock', 'e1', CarbonImmutable::parse('2026-10-01 10:00'), CarbonImmutable::parse('2026-10-01 11:00'), 'confirmed', false, serviceRef: '5', staffRef: '9');
        $b = new ExternalReservationData('other', 'e2', CarbonImmutable::parse('2026-10-01 10:00'), CarbonImmutable::parse('2026-10-01 11:00'), 'booked', false, serviceRef: '5', staffRef: '9');

        // provider / externalId / status 語彙違いは fingerprint に影響しない（時刻・参照・cancel だけ）。
        $this->assertSame($a->fingerprint(), $b->fingerprint());

        $c = new ExternalReservationData('mock', 'e1', CarbonImmutable::parse('2026-10-01 12:00'), CarbonImmutable::parse('2026-10-01 13:00'), 'confirmed', false, serviceRef: '5', staffRef: '9');
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
    }
}
