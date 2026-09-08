<?php

declare(strict_types=1);

namespace Tests\Unit\Reservation;

use App\Enums\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

class ReservationEnumTest extends TestCase
{
    public function test_status_can_be_created_from_its_backed_value(): void
    {
        $this->assertSame(
            ReservationStatus::Confirmed,
            ReservationStatus::from('confirmed'),
        );
    }

    public function test_terminal_statuses_are_identified(): void
    {
        $this->assertFalse(ReservationStatus::PendingPayment->isTerminal());
        $this->assertFalse(ReservationStatus::PendingExternalSync->isTerminal());
        $this->assertFalse(ReservationStatus::Confirmed->isTerminal());
        $this->assertTrue(ReservationStatus::Completed->isTerminal());
        $this->assertTrue(ReservationStatus::NoShow->isTerminal());
        $this->assertTrue(ReservationStatus::Canceled->isTerminal());
        $this->assertTrue(ReservationStatus::Expired->isTerminal());
    }
}
