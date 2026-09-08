<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Services;

use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_users_without_services_manage_permission_cannot_view_index(): void
    {
        foreach (['staff', 'manager', 'customer'] as $role) {
            $user = User::factory()->create([
                'two_factor_confirmed_at' => now(),
            ]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->get('/admin/services')
                ->assertForbidden();
        }
    }

    public function test_admin_with_confirmed_two_factor_can_view_index(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/services')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Services/Index')
                ->has('services', 0)
                ->where('auth.can.servicesManage', true));
    }

    public function test_admin_can_create_service_with_staff_and_audit_log(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('山田');

        $response = $this->actingAs($admin)->post('/admin/services', [
            'name' => '整体60分',
            'duration_min' => 60,
            'price' => 8800,
            'category' => '整体',
            'color' => '#336699',
            'is_online_bookable' => true,
            'requires_staff' => true,
            'sort_order' => 10,
            'staff_ids' => [$staff->user_id],
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.services.index'));

        $service = Service::query()->where('name', '整体60分')->firstOrFail();

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'duration_min' => 60,
            'price' => 8800,
            'is_online_bookable' => true,
            'requires_staff' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('service_staff', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'service.created',
            'entity_type' => Service::class,
            'entity_id' => (string) $service->id,
        ]);
    }

    public function test_admin_can_update_service_and_replace_staff(): void
    {
        $admin = $this->admin();
        $oldStaff = $this->staff('旧担当');
        $newStaff = $this->staff('新担当');
        $service = Service::query()->create([
            'name' => '旧サービス',
            'duration_min' => 30,
            'price' => 3000,
            'requires_staff' => true,
        ]);
        $service->staff()->attach($oldStaff->user_id);

        $response = $this->actingAs($admin)->put("/admin/services/{$service->id}", [
            'name' => '更新サービス',
            'duration_min' => 45,
            'price' => 5500,
            'category' => 'コンディショニング',
            'color' => '#abcdef',
            'is_online_bookable' => false,
            'requires_staff' => true,
            'sort_order' => 5,
            'staff_ids' => [$newStaff->user_id],
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'name' => '更新サービス',
            'duration_min' => 45,
            'price' => 5500,
            'is_online_bookable' => false,
            'sort_order' => 5,
        ]);
        $this->assertDatabaseMissing('service_staff', [
            'service_id' => $service->id,
            'staff_id' => $oldStaff->user_id,
        ]);
        $this->assertDatabaseHas('service_staff', [
            'service_id' => $service->id,
            'staff_id' => $newStaff->user_id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.staff_set']);
    }

    public function test_admin_can_toggle_active_without_deleting_service(): void
    {
        $admin = $this->admin();
        $service = Service::query()->create([
            'name' => '無効化対象',
            'duration_min' => 30,
            'price' => 3000,
        ]);

        $this->actingAs($admin)
            ->patch("/admin/services/{$service->id}/active", ['active' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service.active_changed',
            'entity_id' => (string) $service->id,
        ]);
    }

    public function test_staff_is_required_when_service_requires_staff(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/services', [
                'name' => '担当必須',
                'duration_min' => 60,
                'price' => 5000,
                'requires_staff' => true,
                'sort_order' => 0,
                'staff_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_ids');

        $this->assertDatabaseMissing('services', ['name' => '担当必須']);
    }

    public function test_nonexistent_staff_id_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/services', [
                'name' => '不正な担当',
                'duration_min' => 60,
                'price' => 5000,
                'requires_staff' => true,
                'sort_order' => 0,
                'staff_ids' => [999999],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_ids.0');

        $this->assertDatabaseMissing('services', ['name' => '不正な担当']);
    }

    public function test_index_orders_active_services_first_then_by_sort_order(): void
    {
        $admin = $this->admin();

        Service::query()->create([
            'name' => '無効・先頭順',
            'duration_min' => 30,
            'price' => 1000,
            'requires_staff' => false,
            'is_active' => false,
            'sort_order' => -10,
        ]);
        Service::query()->create([
            'name' => '有効・2番',
            'duration_min' => 30,
            'price' => 1000,
            'requires_staff' => false,
            'sort_order' => 20,
        ]);
        Service::query()->create([
            'name' => '有効・1番',
            'duration_min' => 30,
            'price' => 1000,
            'requires_staff' => false,
            'sort_order' => 10,
        ]);

        $this->actingAs($admin)
            ->get('/admin/services')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('services.0.name', '有効・1番')
                ->where('services.1.name', '有効・2番')
                ->where('services.2.name', '無効・先頭順'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function staff(string $displayName): Staff
    {
        $user = User::factory()->create();

        return Staff::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
            'is_bookable' => true,
            'sort_order' => 0,
        ]);
    }
}
