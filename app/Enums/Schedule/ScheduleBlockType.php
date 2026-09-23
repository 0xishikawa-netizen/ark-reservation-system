<?php

declare(strict_types=1);

namespace App\Enums\Schedule;

enum ScheduleBlockType: string
{
    case Break = 'BREAK';
    case Meeting = 'MEETING';
    case Admin = 'ADMIN';
    case Cleaning = 'CLEANING';
    case Training = 'TRAINING';
    case Out = 'OUT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Break => '休憩',
            self::Meeting => 'ミーティング',
            self::Admin => '事務作業',
            self::Cleaning => '清掃',
            self::Training => '研修',
            self::Out => '外出',
            self::Other => 'その他',
        };
    }
}
