<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Booths;

use App\Models\Booth;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoothManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_users_without_booths_manage_permission_cannot_view_index(): void
    {
        foreach (['staff', 'manager', 'customer'] as $role) {
            $user = User::factory()->create([
                'two_factor_confirmed_at' => now(),
            ]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->get('/admin/booths')
                ->assertForbidden();
        }
    }

    public function test_admin_with_confirmed_two_factor_can_view_index(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/booths')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Booths/Index')
                ->has('booths', 0)
                ->where('auth.can.boothsManage', true));
    }

    public function test_admin_can_create_booth_with_one_audit_log(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/booths', [
            'name' => '施術室A',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.booths.index'));

        $booth = Booth::query()->where('name', '施術室A')->firstOrFail();

        $this->assertDatabaseHas('booths', [
            'id' => $booth->id,
            'name' => '施術室A',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'booth.created',
            'entity_type' => Booth::class,
            'entity_id' => (string) $booth->id,
        ]);
    }

    public function test_store_defaults_booth_to_active(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/booths', [
            'name' => '既定値確認',
            'sort_order' => null,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('booths', [
            'name' => '既定値確認',
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_booth(): void
    {
        $admin = $this->admin();
        $booth = Booth::query()->create([
            'name' => '旧ブース',
            'sort_order' => 20,
        ]);

        $response = $this->actingAs($admin)->put("/admin/booths/{$booth->id}", [
            'name' => '更新ブース',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.booths.index'));

        $this->assertDatabaseHas('booths', [
            'id' => $booth->id,
            'name' => '更新ブース',
            'sort_order' => 5,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booth.updated',
            'entity_id' => (string) $booth->id,
        ]);
    }

    public function test_admin_can_toggle_active_without_deleting_booth(): void
    {
        $admin = $this->admin();
        $booth = Booth::query()->create([
            'name' => '無効化対象',
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->patch("/admin/booths/{$booth->id}/active", ['active' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('booths', [
            'id' => $booth->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('booths', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booth.active_changed',
            'entity_id' => (string) $booth->id,
        ]);
    }

    public function test_index_orders_active_booths_first_then_by_sort_order(): void
    {
        $admin = $this->admin();

        Booth::query()->create([
            'name' => '無効・先頭順',
            'is_active' => false,
            'sort_order' => 0,
        ]);
        Booth::query()->create([
            'name' => '有効・2番',
            'sort_order' => 20,
        ]);
        Booth::query()->create([
            'name' => '有効・1番',
            'sort_order' => 10,
        ]);

        $this->actingAs($admin)
            ->get('/admin/booths')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booths.0.name', '有効・1番')
                ->where('booths.1.name', '有効・2番')
                ->where('booths.2.name', '無効・先頭順'));
    }

    public function test_index_filters_booths_by_name(): void
    {
        $admin = $this->admin();

        Booth::query()->create(['name' => '施術室A']);
        Booth::query()->create(['name' => 'トレーニング区画']);

        $this->actingAs($admin)
            ->get('/admin/booths?search=施術室')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('booths', 1)
                ->where('booths.0.name', '施術室A')
                ->where('filters.search', '施術室'));
    }

    public function test_empty_name_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/booths', [
                'name' => '',
                'sort_order' => 0,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('booths', 0);
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
