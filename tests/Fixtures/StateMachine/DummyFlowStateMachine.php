<?php

declare(strict_types=1);

namespace Tests\Fixtures\StateMachine;

use App\Support\StateMachine\StateMachine;

class DummyFlowStateMachine extends StateMachine
{
    /**
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            'draft' => ['active'],
            'active' => ['done', 'archived'],
            'done' => [],
            'archived' => [],
        ];
    }
}
