<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Tickets;

use App\Domain\Ticket\TicketLedgerService;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerTicketViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_manager_and_staff_can_view_customer_ticket_balances_and_history(): void
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create([
            'name' => '閲覧用5回券',
            'total_count' => 5,
        ]);
        $wallet = app(TicketLedgerService::class)->grant(
            $customer,
            $product,
            5,
            'customer-view-grant',
            '表示確認用',
        );

        foreach (['admin', 'manager', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get("/admin/customers/{$customer->user_id}/tickets")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Customers/Tickets')
                    ->where('customer.user_id', $customer->user_id)
                    ->has('wallets', 1)
                    ->where('wallets.0.id', $wallet->id)
                    ->where('wallets.0.product_name', '閲覧用5回券')
                    ->where('wallets.0.available', 5)
                    ->where('wallets.0.held', 0)
                    ->where('wallets.0.total', 5)
                    ->where('wallets.0.balance_cache', 5)
                    ->has('history', 1)
                    ->where('history.0.wallet_id', $wallet->id)
                    ->where('history.0.type', 'GRANT')
                    ->where('history.0.delta', 5)
                    ->where('can.grant', in_array($role, ['admin', 'manager'], true)));
        }
    }

    public function test_customer_role_cannot_view_another_customers_ticket_page(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->userWithRole('customer'))
            ->get("/admin/customers/{$customer->user_id}/tickets")
            ->assertForbidden();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
