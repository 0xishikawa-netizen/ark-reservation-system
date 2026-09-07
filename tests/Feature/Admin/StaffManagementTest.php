<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_staff_after_password_confirmation(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post('/admin/staff', [
                'name' => '管理 花子',
                'email' => 'new-staff@example.com',
                'display_name' => '花子',
                'role' => 'staff',
                'color' => '#336699',
                'is_bookable' => true,
            ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.staff.index'));

        $createdUser = User::query()
            ->where('email', 'new-staff@example.com')
            ->firstOrFail();

        $this->assertTrue($createdUser->hasRole('staff'));
        $this->assertNotNull($createdUser->email_verified_at);
        $this->assertDatabaseHas('staff', [
            'user_id' => $createdUser->id,
            'display_name' => '花子',
            'color' => '#336699',
            'is_bookable' => true,
        ]);
        Notification::assertSentTo($createdUser, ResetPassword::class);
    }

    public function test_manager_and_staff_without_manage_permission_cannot_create_staff(): void
    {
        Notification::fake();

        foreach (['manager', 'staff'] as $role) {
            $user = User::factory()->create([
                'two_factor_confirmed_at' => now(),
            ]);
            $user->assignRole($role);

            $email = "blocked-{$role}@example.com";

            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->post('/admin/staff', [
                    'name' => 'Blocked User',
                    'email' => $email,
                    'display_name' => 'Blocked',
                    'role' => 'staff',
                ])
                ->assertForbidden();

            $this->assertDatabaseMissing('users', [
                'email' => $email,
            ]);
        }

        Notification::assertNothingSent();
    }

    public function test_admin_role_is_rejected_when_creating_staff(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson('/admin/staff', [
                'name' => 'Rejected Admin',
                'email' => 'rejected-admin@example.com',
                'display_name' => 'Rejected',
                'role' => 'admin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', [
            'email' => 'rejected-admin@example.com',
        ]);
        Notification::assertNothingSent();
    }

    public function test_staff_create_page_requires_recent_password_confirmation(): void
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/staff/create')
            ->assertRedirect(route('password.confirm'));
    }
}
