<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/** 初診時満年齢からの年代区分。表示名と機械可読codeを一箇所で定義する。 */
final class AgeDecadeBucket
{
    /** @var array<string, string> */
    public const LABELS = [
        '0-9' => 'messages.reporting.age_0_9',
        '10-19' => 'messages.reporting.age_10_19',
        '20-29' => 'messages.reporting.age_20_29',
        '30-39' => 'messages.reporting.age_30_39',
        '40-49' => 'messages.reporting.age_40_49',
        '50-59' => 'messages.reporting.age_50_59',
        '60-69' => 'messages.reporting.age_60_69',
        '70-79' => 'messages.reporting.age_70_79',
        '80-89' => 'messages.reporting.age_80_89',
        '90+' => 'messages.reporting.age_90_plus',
    ];

    public static function codeFor(?int $age): ?string
    {
        if ($age === null || $age < 0) {
            return null;
        }
        if ($age >= 90) {
            return '90+';
        }
        $lower = intdiv($age, 10) * 10;

        return "{$lower}-".($lower + 9);
    }

    public static function labelFor(?string $code): ?string
    {
        return $code === null || ! isset(self::LABELS[$code]) ? null : __(self::LABELS[$code]);
    }
}
