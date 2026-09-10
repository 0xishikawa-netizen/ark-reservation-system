<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\CancellationPolicy;
use App\Domain\Reservation\CancellationPolicyResolver;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Setting;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
    }

    public function test_default_tiers_select_refund_percent_by_hours_until_start(): void
    {
        $reservation = Reservation::factory()->create([
            'starts_at' => '2026-09-13 12:00:00',
            'ends_at' => '2026-09-13 13:00:00',
        ]);
        $policy = app(CancellationPolicy::class);

        $this->assertSame(100, $policy->refundPercentFor(
            $reservation,
            CarbonImmutable::parse('2026-09-10 12:00:00'),
        ));
        $this->assertSame(50, $policy->refundPercentFor(
            $reservation,
            CarbonImmutable::parse('2026-09-12 00:00:00'),
        ));
        $this->assertSame(0, $policy->refundPercentFor(
            $reservation,
            CarbonImmutable::parse('2026-09-13 00:00:00'),
        ));
        $this->assertSame(0, $policy->refundPercentFor(
            $reservation,
            CarbonImmutable::parse('2026-09-13 13:00:00'),
        ));
    }

    public function test_no_show_uses_its_independent_setting(): void
    {
        app(Settings::class)->set('reservation.no_show_refund_percent', 25, 'int');
        $reservation = Reservation::factory()->create([
            'starts_at' => '2026-09-13 12:00:00',
            'ends_at' => '2026-09-13 13:00:00',
        ]);

        $this->assertSame(25, app(CancellationPolicy::class)->refundPercentFor(
            $reservation,
            CarbonImmutable::parse('2026-09-10 12:00:00'),
            true,
        ));
    }

    public function test_refundable_amount_floors_and_never_exceeds_unrefunded_remainder(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 1001,
            'refunded_amount' => 0,
        ]);
        $policy = app(CancellationPolicy::class);

        $this->assertSame(500, $policy->refundableAmount($payment, 50));

        $payment->forceFill(['refunded_amount' => 800]);

        $this->assertSame(201, $policy->refundableAmount($payment, 50));
    }

    public function test_json_string_settings_override_config_and_are_sorted_descending(): void
    {
        app(Settings::class)->set(
            'reservation.cancellation_tiers',
            '[{"min_hours_before":0,"refund_percent":10},{"min_hours_before":72,"refund_percent":80}]',
            'string',
        );
        app(Settings::class)->set('reservation.no_show_refund_percent', 30, 'int');
        $resolver = app(CancellationPolicyResolver::class);

        $this->assertSame([
            ['min_hours_before' => 72, 'refund_percent' => 80],
            ['min_hours_before' => 0, 'refund_percent' => 10],
        ], $resolver->tiers());
        $this->assertSame(30, $resolver->noShowRefundPercent());
    }

    public function test_invalid_settings_fall_back_to_config_defaults(): void
    {
        Setting::query()->whereKey('reservation.cancellation_tiers')->update([
            'value' => '{invalid-json',
            'type' => 'json',
        ]);
        Setting::query()->whereKey('reservation.no_show_refund_percent')->update([
            'value' => '150',
            'type' => 'int',
        ]);
        $resolver = app(CancellationPolicyResolver::class);

        $this->assertSame(config('reservation.cancellation.tiers'), $resolver->tiers());
        $this->assertSame(
            config('reservation.cancellation.no_show_refund_percent'),
            $resolver->noShowRefundPercent(),
        );
    }
}
