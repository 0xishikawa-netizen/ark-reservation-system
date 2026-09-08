<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\CustomerPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_allows_admin_and_the_customer_themself_but_denies_manager_for_another_customer(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = $this->userWithRole('admin');
        $manager = $this->userWithRole('manager');
        $customerUser = $this->userWithRole('customer');
        $customer = $this->customerFor($customerUser, 'コキャク');

        $policy = app(CustomerPolicy::class);

        $this->assertTrue($policy->update($admin, $customer));
        $this->assertFalse($policy->update($manager, $customer));
        $this->assertTrue($policy->update($customerUser, $customer));
    }

    public function test_customer_cannot_update_another_customers_record(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $userA = $this->userWithRole('customer');
        $customerA = $this->customerFor($userA, 'コキャク エー');

        $userB = $this->userWithRole('customer');
        $customerB = $this->customerFor($userB, 'コキャク ビー');

        $policy = app(CustomerPolicy::class);

        $this->assertTrue($policy->update($userA, $customerA));
        $this->assertFalse($policy->update($userA, $customerB));
    }

    public function test_manager_and_staff_can_view_customer_records(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = $this->userWithRole('manager');
        $staff = $this->userWithRole('staff');
        $customerUser = $this->userWithRole('customer');
        $customer = $this->customerFor($customerUser, 'エツラン コキャク');

        $policy = app(CustomerPolicy::class);

        $this->assertTrue($policy->view($manager, $customer));
        $this->assertTrue($policy->view($staff, $customer));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function customerFor(User $user, string $kana): Customer
    {
        return Customer::query()->create([
            'user_id' => $user->id,
            'kana' => $kana,
        ]);
    }
}
