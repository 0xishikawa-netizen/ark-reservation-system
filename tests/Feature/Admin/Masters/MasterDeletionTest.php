<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Masters;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketProduct;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マスタの削除（未使用のみ・論理削除）と復元。admin 専用・使用中は削除不可。
 */
final class MasterDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_can_delete_unused_master_and_restore_it(): void
    {
        $admin = $this->userWithRole('admin');
        $product = Product::factory()->create(['name' => '未使用の商品', 'is_active' => true]);

        $this->actingAs($admin)->delete("/admin/masters/products/{$product->id}")
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertFalse((bool) Product::withTrashed()->find($product->id)?->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'master.deleted.products']);

        $this->actingAs($admin)->post("/admin/masters/products/{$product->id}/restore")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull(Product::query()->find($product->id));
    }

    public function test_master_in_use_cannot_be_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $service = Service::factory()->create(['is_active' => true]);
        Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $service->id,
            'staff_id' => null,
            'booth_id' => null,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $this->actingAs($admin)->from('/admin/services')->delete("/admin/masters/services/{$service->id}")
            ->assertRedirect('/admin/services')->assertSessionHasErrors('delete');

        $this->assertNotSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_config_links_do_not_block_deletion(): void
    {
        $admin = $this->userWithRole('admin');
        $service = Service::factory()->create(['is_active' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $service->booths()->attach($booth->id);

        $this->actingAs($admin)->delete("/admin/masters/booths/{$booth->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('booths', ['id' => $booth->id]);
    }

    public function test_only_admin_can_delete(): void
    {
        $manager = $this->userWithRole('manager');
        $ticket = TicketProduct::factory()->create();

        $this->actingAs($manager)->delete("/admin/masters/ticket-products/{$ticket->id}")->assertForbidden();
        $this->assertNotSoftDeleted('ticket_products', ['id' => $ticket->id]);
    }

    public function test_unknown_type_is_not_found(): void
    {
        $this->actingAs($this->userWithRole('admin'))->delete('/admin/masters/users/1')->assertNotFound();
    }

    public function test_deleted_staff_future_shifts_are_removed_and_trashed_list_is_shown_to_admin(): void
    {
        $admin = $this->userWithRole('admin');
        $staff = Staff::factory()->create(['display_name' => '削除する人']);

        $this->actingAs($admin)->delete("/admin/masters/staff/{$staff->user_id}")->assertSessionHasNoErrors();

        $this->assertSoftDeleted('staff', ['user_id' => $staff->user_id]);
        $this->actingAs($admin)->get('/admin/staff')
            ->assertInertia(fn ($page) => $page->where('trashed.0.name', '削除する人'));
    }
}
