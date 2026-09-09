<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config()->set('stripe.key', 'pk_test_xxx');
        config()->set('stripe.secret', 'sk_test_xxx');
    }

    public function test_customer_cannot_view_system_status(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->get('/admin/system/status')
            ->assertForbidden();
    }

    public function test_staff_without_failed_jobs_permission_cannot_view_system_status(): void
    {
        $staffRole = Role::findByName('staff');
        $staffRole->syncPermissions(['admin.access']);

        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole($staffRole);

        $this->actingAs($staff)
            ->get('/admin/system/status')
            ->assertForbidden();
    }

    public function test_admin_can_view_system_status_without_stripe_secret_exposure(): void
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/admin/system/status');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/System/Status')
                ->where('stripe_mode', 'placeholder')
                ->where('reservation_authority', 'local')
                ->where('real_stripe_test_mode_qa', 'incomplete')
                ->where('membership_production_readiness', 'not_ready'));

        $content = $response->getContent();

        $this->assertStringNotContainsString('sk_test', $content);
        $this->assertStringNotContainsString('sk_live', $content);
    }
}
