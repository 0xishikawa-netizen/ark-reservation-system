<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Products;

use App\Models\Product;
use App\Models\TaxCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_settings_manager_can_create_update_and_disable_product(): void
    {
        $admin = $this->admin();
        $tax = TaxCategory::query()->create(['code' => 'standard', 'name' => '標準税率']);

        $this->actingAs($admin)->post('/admin/products', [
            'code' => 'WATER', 'name' => 'ウォーター', 'price' => 200,
            'tax_category_id' => $tax->id, 'is_active' => true, 'sort_order' => 10,
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/products');

        $product = Product::query()->where('code', 'WATER')->firstOrFail();
        $this->actingAs($admin)->put("/admin/products/{$product->id}", [
            'code' => 'WATER', 'name' => 'ミネラルウォーター', 'price' => 250,
            'tax_category_id' => $tax->id, 'is_active' => true, 'sort_order' => 20,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch("/admin/products/{$product->id}/active", ['active' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'ミネラルウォーター', 'price' => 250, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deactivated']);
    }

    public function test_product_routes_require_settings_manage_permission(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');
        $this->actingAs($staff)->get('/admin/products')->assertForbidden();
        $this->actingAs($staff)->postJson('/admin/products', [])->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
