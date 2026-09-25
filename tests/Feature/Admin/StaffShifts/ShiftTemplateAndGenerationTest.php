<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\StaffShifts;

use App\Actions\StaffShift\GenerateShiftsFromTemplates;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\StaffShiftException;
use App\Models\StaffShiftTemplate;
use App\Models\StoreCalendarDay;
use App\Models\User;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTemplateAndGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        // 2026-09-14 は月曜日。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 08:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_saves_weekday_templates_including_split_shifts(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->put('/admin/staff-shifts/templates', [
                'staff_id' => $staff->user_id,
                'entries' => [
                    ['weekday' => 1, 'start_at' => '10:00', 'end_at' => '13:00'],
                    ['weekday' => 1, 'start_at' => '15:00', 'end_at' => '19:00'],
                    ['weekday' => 2, 'start_at' => '10:00', 'end_at' => '19:00'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, StaffShiftTemplate::query()->where('staff_id', $staff->user_id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_shift_template.saved']);
    }

    public function test_saving_templates_replaces_the_previous_set(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 3, 'start_at' => '09:00', 'end_at' => '18:00',
        ]);

        $this->actingAs($admin)
            ->put('/admin/staff-shifts/templates', [
                'staff_id' => $staff->user_id,
                'entries' => [['weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, StaffShiftTemplate::query()->where('staff_id', $staff->user_id)->count());
        $this->assertDatabaseMissing('staff_shift_templates', [
            'staff_id' => $staff->user_id, 'weekday' => 3,
        ]);
    }

    public function test_template_validation_rejects_overlap_and_reversed_time(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->putJson('/admin/staff-shifts/templates', [
                'staff_id' => $staff->user_id,
                'entries' => [['weekday' => 1, 'start_at' => '12:00', 'end_at' => '10:00']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries.0.end_at');

        $this->actingAs($admin)
            ->putJson('/admin/staff-shifts/templates', [
                'staff_id' => $staff->user_id,
                'entries' => [
                    ['weekday' => 1, 'start_at' => '10:00', 'end_at' => '13:00'],
                    ['weekday' => 1, 'start_at' => '12:00', 'end_at' => '19:00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries.1.start_at');
    }

    public function test_generation_creates_shifts_on_matching_weekdays_and_is_idempotent(): void
    {
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00',
        ]);
        app(Settings::class)->set('booking.horizon_mode', 'rolling', 'string');
        app(Settings::class)->set('booking.horizon_days', 21, 'int');

        $first = app(GenerateShiftsFromTemplates::class)->execute();
        $countAfterFirst = StaffShift::query()->count();

        $second = app(GenerateShiftsFromTemplates::class)->execute();

        $this->assertGreaterThan(0, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame($countAfterFirst, StaffShift::query()->count());
        $this->assertTrue(
            StaffShift::query()->where('origin', StaffShift::ORIGIN_TEMPLATE)->exists(),
        );
        // すべて月曜日。
        StaffShift::query()->get()->each(function (StaffShift $shift): void {
            $this->assertSame(1, CarbonImmutable::parse((string) $shift->work_date)->dayOfWeek);
        });
    }

    public function test_generation_skips_closed_dates_and_exception_dates_and_manual_dates(): void
    {
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00',
        ]);
        $settings = app(Settings::class);
        $settings->set('booking.horizon_mode', 'rolling', 'string');
        $settings->set('booking.horizon_days', 40, 'int');

        // 対象になる月曜日たち: 9/21, 9/28, 10/5, 10/12 ...
        StoreCalendarDay::query()->create([
            'business_date' => '2026-09-21',
            'status' => StoreCalendarDay::STATUS_CLOSED,
        ]);
        StaffShiftException::query()->create([
            'staff_id' => $staff->user_id, 'exception_date' => '2026-09-28', 'is_off' => true,
        ]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id, 'work_date' => '2026-10-05',
            'start_at' => '11:00', 'end_at' => '15:00', 'origin' => StaffShift::ORIGIN_MANUAL,
        ]);

        app(GenerateShiftsFromTemplates::class)->execute();

        $this->assertDatabaseMissing('staff_shifts', ['work_date' => '2026-09-21', 'origin' => StaffShift::ORIGIN_TEMPLATE]);
        $this->assertDatabaseMissing('staff_shifts', ['work_date' => '2026-09-28', 'origin' => StaffShift::ORIGIN_TEMPLATE]);
        // 手動枠のある日は自動生成が触らない（手動枠だけが残る）。
        $this->assertSame(
            1,
            StaffShift::query()->where('work_date', '2026-10-05')->count(),
        );
        $this->assertSame(
            StaffShift::ORIGIN_MANUAL,
            StaffShift::query()->where('work_date', '2026-10-05')->value('origin'),
        );
    }

    public function test_monthly_release_day_controls_how_far_generation_reaches(): void
    {
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00',
        ]);
        $settings = app(Settings::class);
        $settings->set('booking.horizon_mode', 'monthly', 'string');
        $settings->set('booking.release_day_of_month', 20, 'int');

        // 2026-09-14（release=20 より前）→ 今月末（9/30）まで。10月の月曜は生成しない。
        app(GenerateShiftsFromTemplates::class)->execute();
        $this->assertFalse(StaffShift::query()->where('work_date', '>=', '2026-10-01')->exists());
        $this->assertTrue(StaffShift::query()->where('work_date', '2026-09-21')->exists());

        // release 日を迎えると翌月末まで開放される。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 08:00:00'));
        app(GenerateShiftsFromTemplates::class)->execute();
        $this->assertTrue(StaffShift::query()->where('work_date', '2026-10-26')->exists());
        $this->assertFalse(StaffShift::query()->where('work_date', '>=', '2026-11-01')->exists());
    }

    public function test_exception_off_removes_only_generated_shifts_for_that_day(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        StaffShift::query()->create([
            'staff_id' => $staff->user_id, 'work_date' => '2026-09-21',
            'start_at' => '10:00', 'end_at' => '19:00', 'origin' => StaffShift::ORIGIN_TEMPLATE,
        ]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id, 'work_date' => '2026-09-21',
            'start_at' => '20:00', 'end_at' => '21:00', 'origin' => StaffShift::ORIGIN_MANUAL,
        ]);

        $this->actingAs($admin)
            ->post('/admin/staff-shifts/exceptions', [
                'staff_id' => $staff->user_id,
                'exception_date' => '2026-09-21',
                'is_off' => true,
                'note' => '臨時休み',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('staff_shifts', [
            'staff_id' => $staff->user_id, 'work_date' => '2026-09-21', 'origin' => StaffShift::ORIGIN_TEMPLATE,
        ]);
        $this->assertDatabaseHas('staff_shifts', [
            'staff_id' => $staff->user_id, 'work_date' => '2026-09-21', 'origin' => StaffShift::ORIGIN_MANUAL,
        ]);
        $this->assertDatabaseHas('staff_shift_exceptions', [
            'staff_id' => $staff->user_id, 'exception_date' => '2026-09-21 00:00:00', 'is_off' => true,
        ]);
    }

    public function test_clearing_exception_lets_generation_recreate_the_day(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00',
        ]);
        app(Settings::class)->set('booking.horizon_mode', 'rolling', 'string');
        app(Settings::class)->set('booking.horizon_days', 30, 'int');
        $exception = StaffShiftException::query()->create([
            'staff_id' => $staff->user_id, 'exception_date' => '2026-09-21', 'is_off' => true,
        ]);

        app(GenerateShiftsFromTemplates::class)->execute();
        $this->assertFalse(StaffShift::query()->where('work_date', '2026-09-21')->exists());

        $this->actingAs($admin)
            ->delete("/admin/staff-shifts/exceptions/{$exception->id}")
            ->assertSessionHasNoErrors();

        app(GenerateShiftsFromTemplates::class)->execute();
        $this->assertTrue(StaffShift::query()->where('work_date', '2026-09-21')->exists());
    }

    public function test_index_page_exposes_the_three_tab_payload(): void
    {
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00',
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/staff-shifts?staff_id={$staff->user_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/StaffShifts/Index')
                ->where('selected_staff_id', $staff->user_id)
                ->has('templates', 1)
                ->has('exceptions')
                ->has('booking.horizon_mode')
                ->has('booking.last_bookable_date'));
    }

    public function test_generate_command_is_runnable_and_idempotent(): void
    {
        $staff = $this->staff();
        StaffShiftTemplate::query()->create([
            'staff_id' => $staff->user_id, 'weekday' => 1, 'start_at' => '10:00', 'end_at' => '19:00',
        ]);
        app(Settings::class)->set('booking.horizon_mode', 'rolling', 'string');
        app(Settings::class)->set('booking.horizon_days', 14, 'int');

        $this->artisan('shifts:generate')->assertSuccessful();
        $count = StaffShift::query()->count();
        $this->assertGreaterThan(0, $count);

        $this->artisan('shifts:generate')->assertSuccessful();
        $this->assertSame($count, StaffShift::query()->count());
    }

    public function test_booking_settings_update_persists_and_is_permission_gated(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->putJson('/admin/staff-shifts/booking', $this->bookingPayload())
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->put('/admin/staff-shifts/booking', $this->bookingPayload())
            ->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame('monthly', $settings->get('booking.horizon_mode'));
        $this->assertSame(20, $settings->get('booking.release_day_of_month'));
        $this->assertDatabaseHas('store_calendar_days', [
            'business_date' => '2026-12-31',
            'status' => StoreCalendarDay::STATUS_CLOSED,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'booking_settings.updated']);
    }

    /** @return array<string, mixed> */
    private function bookingPayload(): array
    {
        return [
            'horizon_mode' => 'monthly',
            'horizon_days' => 60,
            'release_day_of_month' => 20,
            'min_lead_minutes' => 60,
            'closed_dates' => ['2026-12-31'],
        ];
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function staff(): Staff
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return Staff::query()->create([
            'user_id' => $user->id,
            'display_name' => '勤務対象',
            'is_bookable' => true,
        ]);
    }
}
