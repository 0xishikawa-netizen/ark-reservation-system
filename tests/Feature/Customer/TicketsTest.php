<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Domain\Ticket\TicketLedgerService;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\TicketProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_their_ticket_wallet_and_history(): void
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create([
            'name' => '顧客用5回券',
            'total_count' => 5,
        ]);
        $wallet = app(TicketLedgerService::class)->grant(
            $customer,
            $product,
            5,
            'customer-ticket-page',
            '顧客画面確認用',
        );

        $this->actingAs($customer->user)
            ->get('/mypage/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Tickets/Index')
                ->has('wallets', 1)
                ->where('wallets.0.id', $wallet->id)
                ->where('wallets.0.product_name', '顧客用5回券')
                ->where('wallets.0.available', 5)
                ->where('wallets.0.held', 0)
                ->where('wallets.0.total', 5)
                ->has('history', 1)
                ->where('history.0.wallet_id', $wallet->id)
                ->where('history.0.type', 'GRANT')
                ->where('history.0.delta', 5));
    }

    public function test_customer_ticket_page_does_not_include_another_customers_data(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();
        $ownWallet = app(TicketLedgerService::class)->grant(
            $customer,
            $product,
            3,
            'own-customer-wallet',
            '本人用',
        );
        app(TicketLedgerService::class)->grant(
            $otherCustomer,
            $product,
            7,
            'other-customer-wallet',
            '別顧客用',
        );

        $this->actingAs($customer->user)
            ->get('/mypage/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Tickets/Index')
                ->has('wallets', 1)
                ->where('wallets.0.id', $ownWallet->id)
                ->has('history', 1)
                ->where('history.0.wallet_id', $ownWallet->id));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/mypage/tickets')
            ->assertRedirect(route('login'));
    }

    public function test_staff_user_without_customer_record_is_forbidden(): void
    {
        $staffUser = Staff::factory()->create()->user;

        $this->actingAs($staffUser)
            ->get('/mypage/tickets')
            ->assertForbidden();
    }
}
