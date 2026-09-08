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

    /**
     * from から to への最短の前進経路を返す（to を含む。到達不能なら空配列）。
     *
     * 遅延・順序逆転した外部イベントで中間状態を観測できなかった場合に、
     * 定義済みの遷移だけを辿って安全に追いつくために使う。
     * $avoid に指定した状態は経由しない（例: failed を経由して先へ進めない）。
     *
     * @param  list<string>  $avoid
     * @return list<string>
     */
    public function pathTo(string $from, string $to, array $avoid = []): array
    {
        if ($from === $to) {
            return [];
        }

        $transitions = $this->transitions();
        $queue = [[$from, []]];
        $seen = [$from => true];

        while ($queue !== []) {
            [$current, $path] = array_shift($queue);

            foreach ($transitions[$current] ?? [] as $next) {
                if (isset($seen[$next]) || in_array($next, $avoid, true)) {
                    continue;
                }

                $nextPath = [...$path, $next];

                if ($next === $to) {
                    return $nextPath;
                }

                $seen[$next] = true;
                $queue[] = [$next, $nextPath];
            }
        }

        return [];
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
