<?php

declare(strict_types=1);

namespace App\Support\StateMachine\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class StateTransitioned
{
    public function __construct(
        public Model $model,
        public string $column,
        public string $from,
        public string $to,
    ) {}
}
