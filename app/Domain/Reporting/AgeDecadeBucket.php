<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/** 初診時満年齢からの年代区分。表示名と機械可読codeを一箇所で定義する。 */
final class AgeDecadeBucket
{
    /** @var array<string, string> */
    public const LABELS = [
        '0-9' => '0〜9歳',
        '10-19' => '10代',
        '20-29' => '20代',
        '30-39' => '30代',
        '40-49' => '40代',
        '50-59' => '50代',
        '60-69' => '60代',
        '70-79' => '70代',
        '80-89' => '80代',
        '90+' => '90代以上',
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
        return $code === null ? null : (self::LABELS[$code] ?? null);
    }
}
