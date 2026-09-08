<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Tickets;

use App\Domain\Ticket\TicketLedgerService;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketGrantRevokeAdjustTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_can_grant_a_ticket_after_password_confirmation(): void
    {
        $manager = $this->userWithRole('manager');
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => 5]);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/customers/{$customer->user_id}/tickets/grant", [
                'ticket_product_id' => $product->id,
                'reason' => 'キャンペーン付与',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '回数券を付与しました。');

        $wallet = TicketWallet::query()->firstOrFail();

        $this->assertSame(5, $wallet->balance);
        $this->assertSame(5, $wallet->purchased_count);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => 'GRANT',
            'delta' => 5,
            'reason' => 'キャンペーン付与',
        ]);
        $this->assertDatabaseCount('ticket_transactions', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'ticket.granted',
            'entity_id' => (string) $wallet->id,
        ]);
    }

    public function test_grant_requires_reason_and_operation_key(): void
    {
        $manager = $this->userWithRole('manager');
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();
        $url = "/admin/customers/{$customer->user_id}/tickets/grant";

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->postJson($url, [
                'ticket_product_id' => $product->id,
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->postJson($url, [
                'ticket_product_id' => $product->id,
                'reason' => '理由あり',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('operation_key');

        $this->assertDatabaseCount('ticket_wallets', 0);
    }

    public function test_grant_requires_recent_password_confirmation_without_creating_a_wallet(): void
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();

        $response = $this->actingAs($this->userWithRole('manager'))
            ->post("/admin/customers/{$customer->user_id}/tickets/grant", $this->grantPayload($product));

        $this->assertContains($response->getStatusCode(), [302, 423]);
        $this->assertDatabaseCount('ticket_wallets', 0);
    }

    public function test_staff_and_customer_cannot_grant_tickets(): void
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();

        foreach (['staff', 'customer'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->withSession($this->passwordConfirmedSession())
                ->post("/admin/customers/{$customer->user_id}/tickets/grant", $this->grantPayload($product))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ticket_wallets', 0);
    }

    public function test_grant_is_idempotent_for_the_same_operation_key(): void
    {
        $manager = $this->userWithRole('manager');
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => 7]);
        $payload = $this->grantPayload($product);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($manager)
                ->withSession($this->passwordConfirmedSession())
                ->post("/admin/customers/{$customer->user_id}/tickets/grant", $payload)
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('ticket_wallets', 1);
        $this->assertSame(1, TicketTransaction::query()->where('type', 'GRANT')->count());
        $this->assertSame(7, TicketWallet::query()->firstOrFail()->balance);
        $this->assertSame(1, $this->auditCount('ticket.granted'));
    }

    public function test_revoke_reduces_available_and_rejects_an_excess_amount(): void
    {
        $manager = $this->userWithRole('manager');
        $wallet = $this->walletWithBalance(5);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/ticket-wallets/{$wallet->id}/revoke", [
                'count' => 2,
                'reason' => '誤付与取消',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, app(TicketLedgerService::class)->available($wallet));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'ticket.revoked',
        ]);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->postJson("/admin/ticket-wallets/{$wallet->id}/revoke", [
                'count' => 10,
                'reason' => '超過取消',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket');

        $this->assertSame(3, app(TicketLedgerService::class)->available($wallet));
        $this->assertSame(1, TicketTransaction::query()->where('type', 'REVOKE')->count());
    }

    public function test_adjust_accepts_negative_and_positive_deltas_but_rejects_zero(): void
    {
        $manager = $this->userWithRole('manager');
        $wallet = $this->walletWithBalance(5);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/ticket-wallets/{$wallet->id}/adjust", [
                'delta' => -1,
                'reason' => '棚卸し減算',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(4, app(TicketLedgerService::class)->available($wallet));

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/ticket-wallets/{$wallet->id}/adjust", [
                'delta' => 3,
                'reason' => '棚卸し加算',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(7, app(TicketLedgerService::class)->available($wallet));
        $this->assertSame(2, $this->auditCount('ticket.adjusted'));

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->postJson("/admin/ticket-wallets/{$wallet->id}/adjust", [
                'delta' => 0,
                'reason' => '不正値',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delta');

        $this->assertSame(7, app(TicketLedgerService::class)->available($wallet));
    }

    public function test_revoke_and_adjust_require_password_confirmation_and_ticket_grant_permission(): void
    {
        $wallet = $this->walletWithBalance(5);
        $manager = $this->userWithRole('manager');

        foreach (['revoke' => ['count' => 1], 'adjust' => ['delta' => 1]] as $action => $amount) {
            $payload = [
                ...$amount,
                'reason' => '権限確認',
                'operation_key' => (string) Str::uuid(),
            ];

            $response = $this->actingAs($manager)
                ->withSession(['auth.password_confirmed_at' => null])
                ->post("/admin/ticket-wallets/{$wallet->id}/{$action}", $payload);
            $this->assertContains($response->getStatusCode(), [302, 423]);

            $this->actingAs($this->userWithRole('staff'))
                ->withSession($this->passwordConfirmedSession())
                ->post("/admin/ticket-wallets/{$wallet->id}/{$action}", $payload)
                ->assertForbidden();
        }

        $this->assertSame(5, app(TicketLedgerService::class)->available($wallet));
    }

    /** @return array{ticket_product_id: int, reason: string, operation_key: string} */
    private function grantPayload(TicketProduct $product): array
    {
        return [
            'ticket_product_id' => (int) $product->id,
            'reason' => '管理付与',
            'operation_key' => (string) Str::uuid(),
        ];
    }

    private function walletWithBalance(int $count): TicketWallet
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => $count]);

        return app(TicketLedgerService::class)->grant(
            $customer,
            $product,
            $count,
            (string) Str::uuid(),
            'テスト準備',
        );
    }

    /** @return array<string, int> */
    private function passwordConfirmedSession(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function auditCount(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }
}
