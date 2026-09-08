<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Tickets;

use App\Models\TicketProduct;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_update_and_deactivate_a_ticket_product_with_audits(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post('/admin/ticket-products', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '回数券商品を作成しました。');

        $product = TicketProduct::query()->where('name', '10回券')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'ticket_product.created',
            'entity_type' => TicketProduct::class,
            'entity_id' => (string) $product->id,
        ]);

        $this->actingAs($admin)
            ->put("/admin/ticket-products/{$product->id}", [
                ...$this->payload(),
                'name' => '更新10回券',
                'price' => 44_000,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '回数券商品を更新しました。');

        $this->assertDatabaseHas('ticket_products', [
            'id' => $product->id,
            'name' => '更新10回券',
            'price' => 44_000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket_product.updated',
            'entity_id' => (string) $product->id,
        ]);

        $this->actingAs($admin)
            ->patch("/admin/ticket-products/{$product->id}/active", ['active' => false])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '回数券商品を無効化しました。');

        $this->assertDatabaseHas('ticket_products', [
            'id' => $product->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket_product.deactivated',
            'entity_id' => (string) $product->id,
        ]);
    }

    public function test_only_admin_can_access_ticket_product_routes(): void
    {
        foreach (['manager', 'staff', 'customer'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)
                ->get('/admin/ticket-products')
                ->assertForbidden();

            $this->actingAs($user)
                ->post('/admin/ticket-products', $this->payload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ticket_products', 0);
    }

    public function test_invalid_count_price_and_validity_are_rejected(): void
    {
        $admin = $this->userWithRole('admin');

        foreach ([
            ['field' => 'total_count', 'value' => 0],
            ['field' => 'price', 'value' => -1],
            ['field' => 'validity_days', 'value' => 0],
        ] as $case) {
            $this->actingAs($admin)
                ->postJson('/admin/ticket-products', [
                    ...$this->payload(),
                    $case['field'] => $case['value'],
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($case['field']);
        }

        $this->assertDatabaseCount('ticket_products', 0);
    }

    public function test_index_orders_products_by_sort_order_then_id(): void
    {
        $firstId = TicketProduct::factory()->create([
            'name' => '同順先',
            'sort_order' => 10,
        ]);
        TicketProduct::factory()->create([
            'name' => '先頭',
            'sort_order' => -1,
        ]);
        $secondId = TicketProduct::factory()->create([
            'name' => '同順後',
            'sort_order' => 10,
        ]);

        $this->actingAs($this->userWithRole('admin'))
            ->get('/admin/ticket-products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TicketProducts/Index')
                ->where('ticketProducts.0.name', '先頭')
                ->where('ticketProducts.1.id', $firstId->id)
                ->where('ticketProducts.2.id', $secondId->id)
                ->where('auth.can.ticketProductsManage', true));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => '10回券',
            'total_count' => 10,
            'price' => 40_000,
            'validity_days' => 180,
            'sort_order' => 5,
            'is_active' => true,
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
