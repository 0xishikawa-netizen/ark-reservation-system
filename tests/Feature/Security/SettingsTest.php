<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Support\Settings\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_integer_setting_is_returned_as_an_integer(): void
    {
        $this->seed(SettingsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->assertSame(15, app(Settings::class)->get('reservation.slot_minutes'));
        $this->assertSame('restore', app(Settings::class)->get('ticket.no_show_policy'));
        $this->assertSame(
            'preserve_hold',
            app(Settings::class)->get('ticket.expiration_hold_policy'),
        );
        $this->assertSame('consume', app(Settings::class)->get('membership.no_show_policy'));
        $this->assertSame(8, Setting::query()->count());
    }

    public function test_missing_setting_returns_the_given_default(): void
    {
        $this->assertSame(30, app(Settings::class)->get('missing.setting', 30));
    }

    public function test_set_infers_types_and_invalidates_request_memoization(): void
    {
        $settings = app(Settings::class);

        $this->assertNull($settings->get('feature.enabled'));

        $settings->set('feature.enabled', true);
        $settings->set('display.options', ['compact' => true]);

        $this->assertTrue($settings->get('feature.enabled'));
        $this->assertSame(['compact' => true], $settings->get('display.options'));
    }
}
