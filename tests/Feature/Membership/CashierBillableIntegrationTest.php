<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

final class CashierBillableIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_is_billable_and_stripe_id_aliases_existing_column(): void
    {
        $customer = Customer::factory()->create(['stripe_customer_id' => null]);

        $this->assertFalse($customer->hasStripeId());

        $customer->stripe_id = 'cus_ARK123';
        $customer->save();

        $fresh = $customer->fresh();
        $this->assertSame('cus_ARK123', $fresh->stripe_customer_id, 'Cashier の書き込みは stripe_customer_id へ入る');
        $this->assertSame('cus_ARK123', $fresh->stripe_id);
        $this->assertSame('cus_ARK123', $fresh->stripeId());
        $this->assertTrue($fresh->hasStripeId());
    }

    public function test_cashier_customer_model_is_bound_to_customer(): void
    {
        $this->assertSame(Customer::class, Cashier::$customerModel);
    }

    public function test_cashier_does_not_register_its_own_webhook_route(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r): string => $r->uri())
            ->filter(fn (string $uri): bool => str_contains($uri, 'stripe/webhook'))
            ->values();

        // Phase 5 の単一入口のみ（Cashier の webhook は ignoreRoutes で抑止）。
        $this->assertCount(1, $routes);
        $this->assertSame('stripe/webhook', $routes->first());
    }
}
