<?php

declare(strict_types=1);

namespace Tests\Unit\StateMachine;

use App\Support\StateMachine\Events\StateTransitioned;
use App\Support\StateMachine\InvalidStateTransitionException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\StateMachine\DummyFlow;
use Tests\Fixtures\StateMachine\DummyFlowStateMachine;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('dummy_flows');
        Schema::create('dummy_flows', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dummy_flows');

        parent::tearDown();
    }

    public function test_can_reports_allowed_and_disallowed_transitions(): void
    {
        $machine = new DummyFlowStateMachine;

        $this->assertTrue($machine->can('draft', 'active'));
        $this->assertFalse($machine->can('draft', 'done'));
    }

    public function test_assert_throws_an_exception_containing_transition_context(): void
    {
        $machine = new DummyFlowStateMachine;

        try {
            $machine->assert('draft', 'done');
            $this->fail('不正な状態遷移で例外が発生しませんでした。');
        } catch (InvalidStateTransitionException $exception) {
            $this->assertStringContainsString('draft', $exception->getMessage());
            $this->assertStringContainsString('done', $exception->getMessage());
            $this->assertStringContainsString(DummyFlowStateMachine::class, $exception->getMessage());
        }
    }

    public function test_apply_persists_the_state_and_dispatches_one_event(): void
    {
        Event::fake([StateTransitioned::class]);

        $flow = DummyFlow::query()->create(['status' => 'draft']);
        $machine = new DummyFlowStateMachine;

        $machine->apply($flow, 'status', 'active');

        $this->assertSame('active', $flow->fresh()?->status);
        Event::assertDispatched(
            StateTransitioned::class,
            fn (StateTransitioned $event): bool => $event->model->is($flow)
                && $event->column === 'status'
                && $event->from === 'draft'
                && $event->to === 'active',
        );
        Event::assertDispatchedTimes(StateTransitioned::class, 1);
    }

    public function test_unknown_from_state_is_rejected(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new DummyFlowStateMachine)->assert('unknown', 'active');
    }

    public function test_unknown_to_state_is_rejected(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new DummyFlowStateMachine)->assert('draft', 'unknown');
    }

    public function test_same_state_transition_is_rejected(): void
    {
        $this->expectException(InvalidStateTransitionException::class);

        (new DummyFlowStateMachine)->assert('draft', 'draft');
    }
}
