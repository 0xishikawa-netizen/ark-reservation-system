<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Enums\Ticket\TicketExpirationHoldPolicy;
use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use PHPUnit\Framework\TestCase;

class TicketEnumTest extends TestCase
{
    public function test_enum_values_match_database_literals(): void
    {
        $this->assertSame([
            'PURCHASE',
            'RESERVE_HOLD',
            'RESERVE_RELEASE',
            'CONSUME',
            'GRANT',
            'REVOKE',
            'EXPIRE',
            'ADJUST',
        ], array_column(TicketTransactionType::cases(), 'value'));
        $this->assertSame(
            ['active', 'exhausted', 'expired'],
            array_column(TicketWalletStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['held', 'released', 'consumed'],
            array_column(TicketReservationUsageStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['restore', 'consume'],
            array_column(TicketNoShowPolicy::cases(), 'value'),
        );
        $this->assertSame(
            ['preserve_hold'],
            array_column(TicketExpirationHoldPolicy::cases(), 'value'),
        );
    }

    public function test_only_reservation_transaction_types_require_a_reservation(): void
    {
        foreach (TicketTransactionType::cases() as $type) {
            $expected = in_array($type, [
                TicketTransactionType::ReserveHold,
                TicketTransactionType::ReserveRelease,
                TicketTransactionType::Consume,
            ], true);

            $this->assertSame($expected, $type->requiresReservation(), $type->value);
        }
    }
}
