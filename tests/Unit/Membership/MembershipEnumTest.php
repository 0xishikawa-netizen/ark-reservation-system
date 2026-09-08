<?php

declare(strict_types=1);

namespace Tests\Unit\Membership;

use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use PHPUnit\Framework\TestCase;

final class MembershipEnumTest extends TestCase
{
    public function test_status_values_and_bookability(): void
    {
        $this->assertSame('pending', MembershipStatus::Pending->value);
        $this->assertSame('canceling', MembershipStatus::Canceling->value);

        $this->assertTrue(MembershipStatus::Active->isBookable());
        $this->assertTrue(MembershipStatus::Grace->isBookable());
        $this->assertTrue(MembershipStatus::Canceling->isBookable());
        $this->assertFalse(MembershipStatus::Pending->isBookable());
        $this->assertFalse(MembershipStatus::Paused->isBookable());
        $this->assertFalse(MembershipStatus::Canceled->isBookable());
        $this->assertTrue(MembershipStatus::Canceled->isTerminal());
    }

    public function test_usage_type_literals_and_reservation_requirement(): void
    {
        $this->assertSame('GRANT', MembershipUsageType::Grant->value);
        $this->assertSame('RESERVE', MembershipUsageType::Reserve->value);
        $this->assertSame('RELEASE', MembershipUsageType::Release->value);
        $this->assertSame('CONSUME', MembershipUsageType::Consume->value);
        $this->assertSame('ADJUST', MembershipUsageType::Adjust->value);

        $this->assertTrue(MembershipUsageType::Reserve->requiresReservation());
        $this->assertTrue(MembershipUsageType::Consume->requiresReservation());
        $this->assertFalse(MembershipUsageType::Grant->requiresReservation());
        $this->assertFalse(MembershipUsageType::Adjust->requiresReservation());
    }

    public function test_status_and_policy_string_backing(): void
    {
        $this->assertSame('reserved', MembershipReservationUsageStatus::Reserved->value);
        $this->assertSame('consume', MembershipNoShowPolicy::Consume->value);
        $this->assertSame('restore', MembershipNoShowPolicy::Restore->value);
    }
}
