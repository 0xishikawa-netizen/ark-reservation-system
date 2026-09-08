<?php

declare(strict_types=1);

namespace Tests\Unit\Membership;

use App\Domain\Membership\MembershipStateMachine;
use App\Support\StateMachine\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;

final class MembershipStateMachineTest extends TestCase
{
    private MembershipStateMachine $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm = new MembershipStateMachine;
    }

    public function test_forward_transitions_are_allowed(): void
    {
        $this->assertTrue($this->sm->can('pending', 'active'));
        $this->assertTrue($this->sm->can('active', 'grace'));
        $this->assertTrue($this->sm->can('active', 'canceling'));
        $this->assertTrue($this->sm->can('grace', 'active'));
        $this->assertTrue($this->sm->can('grace', 'paused'));
        $this->assertTrue($this->sm->can('paused', 'active'));
        $this->assertTrue($this->sm->can('canceling', 'canceled'));
        $this->assertTrue($this->sm->can('canceling', 'active'));
    }

    public function test_canceled_is_terminal_and_cannot_be_rewound(): void
    {
        $this->assertFalse($this->sm->can('canceled', 'active'));
        $this->assertFalse($this->sm->can('canceled', 'pending'));
        $this->assertFalse($this->sm->can('active', 'pending'));
        $this->assertSame([], $this->sm->pathTo('canceled', 'active'));
    }

    public function test_assert_throws_on_invalid_transition(): void
    {
        $this->expectException(InvalidStateTransitionException::class);
        $this->sm->assert('canceled', 'active');
    }

    public function test_path_to_walks_defined_forward_edges_only(): void
    {
        // pending -> active -> grace は定義済み経路。
        $this->assertSame(['active', 'grace'], $this->sm->pathTo('pending', 'grace'));
        // paused から canceled への最短。
        $this->assertSame(['canceled'], $this->sm->pathTo('paused', 'canceled'));
    }
}
