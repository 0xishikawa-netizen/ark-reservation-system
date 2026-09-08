<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\NonBoundaryStartException;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class SlotKey
{
    public function __construct(private readonly int $slotMinutes)
    {
        if ($this->slotMinutes < 1) {
            throw new InvalidArgumentException('スロットの分数は 1 以上である必要があります。');
        }
    }

    public static function fromSettings(): self
    {
        return new self((int) app(Settings::class)->get(
            'reservation.slot_minutes',
            (int) config('reservation.slot_minutes', 15),
        ));
    }

    public function slotMinutes(): int
    {
        return $this->slotMinutes;
    }

    public function isBoundary(CarbonInterface $t): bool
    {
        return ((int) $t->format('i')) % $this->slotMinutes === 0
            && (int) $t->format('s') === 0
            && (int) $t->micro === 0;
    }

    /** @return list<CarbonImmutable> */
    public function occupiedSlots(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        bool $adminFreeTime = false,
    ): array {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('終了時刻は開始時刻より後である必要があります。');
        }

        if (! $adminFreeTime && ! $this->isBoundary($startsAt)) {
            throw new NonBoundaryStartException('開始時刻がスロット境界に一致していません。');
        }

        $from = $adminFreeTime
            ? $this->floorToBoundary($startsAt)
            : CarbonImmutable::instance($startsAt);
        $to = $adminFreeTime
            ? $this->ceilToBoundary($endsAt)
            : CarbonImmutable::instance($endsAt);
        $slots = [];

        for ($slot = $from; $slot->lessThan($to); $slot = $slot->addMinutes($this->slotMinutes)) {
            $slots[] = $slot;
        }

        return $slots;
    }

    private function floorToBoundary(CarbonInterface $t): CarbonImmutable
    {
        $minutes = intdiv((int) $t->format('i'), $this->slotMinutes) * $this->slotMinutes;

        return CarbonImmutable::instance($t)
            ->startOfHour()
            ->addMinutes($minutes);
    }

    private function ceilToBoundary(CarbonInterface $t): CarbonImmutable
    {
        $immutable = CarbonImmutable::instance($t);

        if ($this->isBoundary($immutable)) {
            return $immutable;
        }

        return $this->floorToBoundary($immutable)->addMinutes($this->slotMinutes);
    }
}
