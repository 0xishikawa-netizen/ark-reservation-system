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

final class NotificationSettingsTest extends TestCase
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

    public function test_staff_without_settings_permission_cannot_view_or_update(): void
    {
        $staff = $this->roleUser('staff');

        $this->actingAs($staff)->get('/admin/settings/notifications')->assertForbidden();
        $this->actingAs($staff)
            ->patch('/admin/settings/notifications', $this->payload(['enabled' => false]))
            ->assertForbidden();
    }

    public function test_defaults_are_noticeable_in_a_busy_store(): void
    {
        $this->actingAs($this->roleUser('manager'))
            ->get('/admin/settings/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Notifications')
                ->where('settings.enabled', true)
                ->where('settings.type', 'glass')
                ->where('settings.volume', 80)
                ->where('settings.repeat', 'three'));
    }

    public function test_manager_can_save_sound_settings_and_board_receives_them(): void
    {
        $manager = $this->roleUser('manager');

        $this->actingAs($manager)
            ->from('/admin/settings/notifications')
            ->patch('/admin/settings/notifications', $this->payload([
                'type' => 'sparkle',
                'volume' => 100,
                'repeat' => 'until_ack',
            ]))
            ->assertRedirect('/admin/settings/notifications')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::query()->where('action', 'notification_settings.updated')->count());

        $this->actingAs($manager)
            ->get('/admin/schedule')
            ->assertInertia(fn (Assert $page) => $page
                ->where('notification_sound.enabled', true)
                ->where('notification_sound.type', 'sparkle')
                ->where('notification_sound.volume', 100)
                ->where('notification_sound.repeat', 'until_ack'));

        $this->actingAs($manager)
            ->patch('/admin/settings/notifications', $this->payload(['enabled' => false]))
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->get('/admin/schedule')
            ->assertInertia(fn (Assert $page) => $page->where('notification_sound.enabled', false));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->roleUser('manager'))
            ->patch('/admin/settings/notifications', [
                'enabled' => 'loud',
                'type' => 'siren_xl',
                'volume' => 150,
                'repeat' => 'forever',
            ])
            ->assertSessionHasErrors(['enabled', 'type', 'volume', 'repeat']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'type' => 'glass',
            'volume' => 80,
            'repeat' => 'three',
            ...$overrides,
        ];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
