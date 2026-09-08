<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /** @var array<string, array{value: string, type: string}> */
    private const DEFAULTS = [
        'business_hours.open' => ['value' => '10:00', 'type' => 'string'],
        'business_hours.close' => ['value' => '22:00', 'type' => 'string'],
        'reservation.slot_minutes' => ['value' => '15', 'type' => 'int'],
        'reservation.hold_minutes' => ['value' => '10', 'type' => 'int'],
        'admin.idle_timeout' => ['value' => '1800', 'type' => 'int'],
        'ticket.no_show_policy' => ['value' => 'restore', 'type' => 'string'],
        'ticket.expiration_hold_policy' => ['value' => 'preserve_hold', 'type' => 'string'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $setting) {
            Setting::query()->updateOrCreate(['key' => $key], $setting);
        }
    }
}
