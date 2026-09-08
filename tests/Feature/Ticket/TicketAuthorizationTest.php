<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\TicketLedgerService;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\TicketWallet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TicketAuthorizationTest extends TestCase
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

    public function test_only_admin_can_view_and_create_ticket_products(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/ticket-products')
            ->assertOk();
        $this->actingAs($admin)
            ->post('/admin/ticket-products', $this->ticketProductPayload('管理者用回数券'))
            ->assertRedirect();

        foreach (['manager', 'staff', 'customer'] as $role) {
            $actor = $this->userWithRole($role);

            $this->actingAs($actor)
                ->get('/admin/ticket-products')
                ->assertForbidden();
            $this->actingAs($actor)
                ->post('/admin/ticket-products', $this->ticketProductPayload("{$role}用回数券"))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ticket_products', 1);
    }

    public function test_grant_revoke_and_adjust_require_manager_or_admin_and_password_confirmation(): void
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => 5]);
        $wallet = $this->walletWithBalance($customer, $product, 'authorization-wallet');

        foreach (['manager', 'admin'] as $role) {
            $actor = $this->userWithRole($role);

            foreach ($this->sensitiveTicketRequests($customer, $product, $wallet) as $request) {
                $response = $this->actingAs($actor)
                    ->withSession(['auth.password_confirmed_at' => null])
                    ->post($request['uri'], $request['payload']);

                $this->assertContains($response->getStatusCode(), [302, 423]);
            }

            foreach ($this->sensitiveTicketRequests($customer, $product, $wallet) as $request) {
                $this->actingAs($actor)
                    ->withSession($this->passwordConfirmedSession())
                    ->post($request['uri'], $request['payload'])
                    ->assertSessionHasNoErrors()
                    ->assertRedirect();
            }
        }

        foreach (['staff', 'customer'] as $role) {
            $actor = $this->userWithRole($role);

            foreach ($this->sensitiveTicketRequests($customer, $product, $wallet) as $request) {
                $this->actingAs($actor)
                    ->withSession($this->passwordConfirmedSession())
                    ->post($request['uri'], $request['payload'])
                    ->assertForbidden();
            }
        }

        $this->assertSame(5, app(TicketLedgerService::class)->available($wallet));
    }

    public function test_admin_manager_and_staff_can_view_customer_tickets_but_customer_cannot(): void
    {
        $customer = Customer::factory()->create();
        $uri = "/admin/customers/{$customer->user_id}/tickets";

        foreach (['admin', 'manager', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get($uri)
                ->assertOk();
        }

        $this->actingAs($this->userWithRole('customer'))
            ->get($uri)
            ->assertForbidden();
    }

    public function test_only_admin_can_view_and_update_ticket_policy_settings(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/settings/tickets')
            ->assertOk();
        $this->actingAs($admin)
            ->withSession($this->passwordConfirmedSession())
            ->patch('/admin/settings/tickets', $this->ticketPolicyPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        foreach (['manager', 'staff', 'customer'] as $role) {
            $actor = $this->userWithRole($role);

            $this->actingAs($actor)
                ->get('/admin/settings/tickets')
                ->assertForbidden();
            $this->actingAs($actor)
                ->withSession($this->passwordConfirmedSession())
                ->patch('/admin/settings/tickets', $this->ticketPolicyPayload())
                ->assertForbidden();
        }
    }

    public function test_only_a_user_with_a_customer_record_can_view_their_mypage_tickets(): void
    {
        $this->get('/mypage/tickets')
            ->assertRedirect(route('login'));

        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        $this->actingAs($customer->user)
            ->get('/mypage/tickets')
            ->assertOk();

        foreach (['staff', 'manager', 'admin'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/mypage/tickets')
                ->assertForbidden();
        }
    }

    public function test_customer_without_ticket_grant_permission_is_not_bypassed_by_gate_before(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        $this->assertFalse(Gate::forUser($customer->user)->allows('ticket.grant'));
    }

    public function test_grant_without_csrf_token_returns_419(): void
    {
        $this->withMiddleware();

        // testing 環境の CSRF 省略をこのケースだけ解除する。
        $this->app->instance('env', 'csrf-testing');

        $manager = $this->userWithRole('manager');
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/customers/{$customer->user_id}/tickets/grant", [
                'ticket_product_id' => $product->id,
                'reason' => 'CSRF 検証',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertStatus(419);

        $this->assertDatabaseCount('ticket_wallets', 0);
    }

    public function test_ticket_reservation_post_uses_the_existing_reserve_rate_limiter(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $product = TicketProduct::factory()->create(['total_count' => 1]);
        $this->walletWithBalance($customer, $product, 'rate-limit-ticket');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($customer->user)
                ->postJson('/reserve', ['payment_method' => 'ticket'])
                ->assertUnprocessable();
        }

        $this->actingAs($customer->user)
            ->postJson('/reserve', ['payment_method' => 'ticket'])
            ->assertTooManyRequests();
    }

    /**
     * @return list<array{uri: string, payload: array<string, int|string>}>
     */
    private function sensitiveTicketRequests(
        Customer $customer,
        TicketProduct $product,
        TicketWallet $wallet,
    ): array {
        return [
            [
                'uri' => "/admin/customers/{$customer->user_id}/tickets/grant",
                'payload' => [
                    'ticket_product_id' => (int) $product->id,
                    'reason' => '権限マトリクス付与',
                    'operation_key' => (string) Str::uuid(),
                ],
            ],
            [
                'uri' => "/admin/ticket-wallets/{$wallet->id}/revoke",
                'payload' => [
                    'count' => 1,
                    'reason' => '権限マトリクス取消',
                    'operation_key' => (string) Str::uuid(),
                ],
            ],
            [
                'uri' => "/admin/ticket-wallets/{$wallet->id}/adjust",
                'payload' => [
                    'delta' => 1,
                    'reason' => '権限マトリクス調整',
                    'operation_key' => (string) Str::uuid(),
                ],
            ],
        ];
    }

    /** @return array{name: string, total_count: int, price: int, validity_days: int, sort_order: int, is_active: bool} */
    private function ticketProductPayload(string $name): array
    {
        return [
            'name' => $name,
            'total_count' => 5,
            'price' => 20_000,
            'validity_days' => 180,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** @return array{no_show_policy: string, expiration_hold_policy: string, reason: string} */
    private function ticketPolicyPayload(): array
    {
        return [
            'no_show_policy' => 'consume',
            'expiration_hold_policy' => 'preserve_hold',
            'reason' => '権限マトリクス確認',
        ];
    }

    private function walletWithBalance(
        Customer $customer,
        TicketProduct $product,
        string $key,
    ): TicketWallet {
        return app(TicketLedgerService::class)->grant(
            customer: $customer,
            product: $product,
            count: (int) $product->total_count,
            operationKey: $key,
            reason: '認可テスト準備',
        );
    }

    /** @return array<string, int> */
    private function passwordConfirmedSession(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp];
    }

    private function userWithRole(string $role): User
    {
        $user = $role === 'customer'
            ? Customer::factory()->create()->user
            : User::factory()->create();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }
}
