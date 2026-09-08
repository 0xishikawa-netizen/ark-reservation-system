<?php

declare(strict_types=1);

namespace Tests\Unit\Reservation;

use App\Domain\Reservation\ReservationStateMachine;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Reservation;
use App\Support\StateMachine\Events\StateTransitioned;
use App\Support\StateMachine\InvalidStateTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReservationStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_can_transition_to_phase_three_terminal_statuses(): void
    {
        $machine = new ReservationStateMachine;

        $this->assertTrue($machine->can('confirmed', 'completed'));
        $this->assertTrue($machine->can('confirmed', 'canceled'));
        $this->assertTrue($machine->can('confirmed', 'no_show'));
    }

    public function test_same_status_transition_is_rejected(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new ReservationStateMachine)->assert('confirmed', 'confirmed');
    }

    public function test_terminal_status_cannot_transition_back_to_confirmed(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new ReservationStateMachine)->assert('completed', 'confirmed');
    }

    public function test_unknown_from_status_is_rejected(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new ReservationStateMachine)->assert('unknown', 'confirmed');
    }

    public function test_unknown_to_status_is_rejected(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new ReservationStateMachine)->assert('confirmed', 'unknown');
    }

    public function test_apply_persists_status_and_dispatches_one_event(): void
    {
        Event::fake([StateTransitioned::class]);

        $reservation = Reservation::factory()->create();

        (new ReservationStateMachine)->apply(
            $reservation,
            'status',
            ReservationStatus::Completed->value,
        );

        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()?->status);
        Event::assertDispatched(
            StateTransitioned::class,
            fn (StateTransitioned $event): bool => $event->model->is($reservation)
                && $event->column === 'status'
                && $event->from === ReservationStatus::Confirmed->value
                && $event->to === ReservationStatus::Completed->value,
        );
        Event::assertDispatchedTimes(StateTransitioned::class, 1);
    }
}
