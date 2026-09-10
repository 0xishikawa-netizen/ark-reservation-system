<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ReservationPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
        ]);
    }

    public function test_customer_cannot_view_reservation_policy_settings(): void
    {
        $this->actingAs($this->roleUser('customer'))
            ->get('/admin/settings/reservation')
            ->assertForbidden();
    }

    public function test_staff_without_settings_permission_cannot_view_or_update(): void
    {
        $staff = $this->roleUser('staff');

        $this->actingAs($staff)
            ->get('/admin/settings/reservation')
            ->assertForbidden();
        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/reservation', $this->validPayload())
            ->assertForbidden();
    }

    public function test_manager_and_admin_can_view_current_policy(): void
    {
        foreach (['manager', 'admin'] as $role) {
            $this->actingAs($this->roleUser($role))
                ->get('/admin/settings/reservation')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Settings/ReservationPolicy')
                    ->where('policy.tiers.0.min_hours_before', 48)
                    ->where('policy.tiers.0.refund_percent', 100)
                    ->where('policy.tiers.2.min_hours_before', 0)
                    ->where('policy.no_show_refund_percent', 0)
                    ->where('auth.can.settingsManage', true));
        }
    }

    public function test_update_requires_recent_password_confirmation(): void
    {
        $this->actingAs($this->roleUser('manager'))
            ->patch('/admin/settings/reservation', $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('settings', [
            'key' => 'reservation.no_show_refund_percent',
            'value' => '0',
        ]);
    }

    public function test_valid_update_persists_sorted_policy_and_audits_old_to_new_summary(): void
    {
        $manager = $this->roleUser('manager');

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/reservation', $this->validPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '予約キャンセルポリシーを更新しました。')
            ->assertRedirect();

        $this->assertDatabaseHas('settings', [
            'key' => 'reservation.cancellation_tiers',
            'value' => '[{"min_hours_before":72,"refund_percent":90},{"min_hours_before":0,"refund_percent":20}]',
            'type' => 'json',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'reservation.no_show_refund_percent',
            'value' => '10',
            'type' => 'int',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'reservation_policy.updated',
            'entity_type' => null,
            'entity_id' => null,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $audit = AuditLog::query()->sole();
        $this->assertStringContainsString('48h=100%', (string) $audit->summary);
        $this->assertStringContainsString('72h=90%', (string) $audit->summary);
    }

    public function test_tiers_without_zero_hour_fallback_are_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['tiers'] = [
            ['min_hours_before' => 72, 'refund_percent' => 90],
            ['min_hours_before' => 24, 'refund_percent' => 20],
        ];

        $this->actingAs($this->roleUser('admin'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patchJson('/admin/settings/reservation', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers');
    }

    public function test_refund_percent_above_100_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['tiers'][0]['refund_percent'] = 150;

        $this->actingAs($this->roleUser('admin'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patchJson('/admin/settings/reservation', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers.0.refund_percent');
    }

    /** @return array{tiers: list<array{min_hours_before: int, refund_percent: int}>, no_show_refund_percent: int} */
    private function validPayload(): array
    {
        return [
            'tiers' => [
                ['min_hours_before' => 0, 'refund_percent' => 20],
                ['min_hours_before' => 72, 'refund_percent' => 90],
            ],
            'no_show_refund_percent' => 10,
        ];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
