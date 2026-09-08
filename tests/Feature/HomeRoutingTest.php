<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_verified_customer_sees_customer_dashboard(): void
    {
        $customer = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $customer->assignRole('customer');
        Customer::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($customer)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Dashboard')
                ->has('tickets')
                ->has('attention'));
    }

    public function test_staff_with_confirmed_two_factor_is_redirected_to_admin(): void
    {
        $staff = User::factory()->create([
            'email_verified_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get('/')
            ->assertRedirect('/admin');
    }
}
