<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use App\Support\StateMachine\Events\StateTransitioned;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * 文字列状態の遷移を一元管理する基盤。
 *
 * 同一状態への遷移は変化のない処理を明示的に呼ばない方針とし、既定で許可しない。
 */
abstract class StateMachine
{
    /**
     * @return array<string, list<string>>
     */
    abstract protected function transitions(): array;

    public function can(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        $transitions = $this->transitions();

        return array_key_exists($from, $transitions)
            && in_array($to, $transitions[$from], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->can($from, $to)) {
            throw new InvalidStateTransitionException(static::class, $from, $to);
        }
    }

    public function apply(Model $model, string $column, string $to): void
    {
        $current = $model->{$column};
        $from = $current instanceof BackedEnum
            ? (string) $current->value
            : (string) $current;

        $this->assert($from, $to);

        $model->{$column} = $to;
        $model->save();

        event(new StateTransitioned($model, $column, $from, $to));
    }
}
