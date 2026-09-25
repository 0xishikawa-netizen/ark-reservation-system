<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/** 半開区間 [start,end) を分単位で扱い、重複・包含を二重計上しない。 */
final class MinuteIntervals
{
    /** @param list<array{0:int,1:int}> $ranges @return list<array{0:int,1:int}> */
    public static function union(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($end, $merged[$last][1]);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    public static function minutes(array $ranges): int
    {
        return array_sum(array_map(static fn (array $range): int => $range[1] - $range[0], self::union($ranges)));
    }

    /** @param list<array{0:int,1:int}> $left @param list<array{0:int,1:int}> $right @return list<array{0:int,1:int}> */
    public static function intersect(array $left, array $right): array
    {
        $result = [];
        foreach (self::union($left) as [$a, $b]) {
            foreach (self::union($right) as [$c, $d]) {
                if (min($b, $d) > max($a, $c)) {
                    $result[] = [max($a, $c), min($b, $d)];
                }
            }
        }

        return self::union($result);
    }

    /** @param list<array{0:int,1:int}> $base @param list<array{0:int,1:int}> $blocked */
    public static function minusMinutes(array $base, array $blocked): int
    {
        return self::minutes(self::subtract($base, $blocked));
    }

    /** @param list<array{0:int,1:int}> $base @param list<array{0:int,1:int}> $blocked @return list<array{0:int,1:int}> */
    public static function subtract(array $base, array $blocked): array
    {
        $remaining = self::union($base);
        foreach (self::union($blocked) as [$blockStart, $blockEnd]) {
            $next = [];
            foreach ($remaining as [$start, $end]) {
                if ($blockEnd <= $start || $blockStart >= $end) {
                    $next[] = [$start, $end];

                    continue;
                }
                if ($blockStart > $start) {
                    $next[] = [$start, $blockStart];
                }
                if ($blockEnd < $end) {
                    $next[] = [$blockEnd, $end];
                }
            }
            $remaining = $next;
        }

        return $remaining;
    }
}
