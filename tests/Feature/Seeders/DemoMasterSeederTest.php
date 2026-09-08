<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Customer;
use App\Models\User;
use App\Support\Security\PiiHasher;
use Database\Seeders\DemoMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoMasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_environment_creates_demo_masters_idempotently(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->seed(DemoMasterSeeder::class);

        $countsAfterFirstRun = $this->demoCounts();

        $this->assertSame([
            'users' => 5,
            'admin_users' => 1,
            'staff_users' => 2,
            'staff_records' => 3,
            'services' => 2,
            'service_staff' => 4,
            'booths' => 2,
            'staff_shifts' => 4,
            'customers' => 2,
        ], $countsAfterFirstRun);

        $admin = User::query()->where('email', 'admin@ark.local')->firstOrFail();

        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertDatabaseHas('staff', [
            'user_id' => $admin->id,
            'is_bookable' => false,
        ]);
        $this->assertDatabaseHas('customers', [
            'phone_hmac' => PiiHasher::phoneHmac('09012345678'),
        ]);

        $this->seed(DemoMasterSeeder::class);

        $this->assertSame($countsAfterFirstRun, $this->demoCounts());
    }

    public function test_non_local_environment_skips_all_demo_data(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');

        $this->seed(DemoMasterSeeder::class);

        $this->assertSame([
            'users' => 0,
            'admin_users' => 0,
            'staff_users' => 0,
            'staff_records' => 0,
            'services' => 0,
            'service_staff' => 0,
            'booths' => 0,
            'staff_shifts' => 0,
            'customers' => 0,
        ], $this->demoCounts());
    }

    /** @return array<string, int> */
    private function demoCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'admin_users' => $this->userCountWithRole('admin'),
            'staff_users' => $this->userCountWithRole('staff'),
            'staff_records' => $this->getConnection()->table('staff')->count(),
            'services' => $this->getConnection()->table('services')->count(),
            'service_staff' => $this->getConnection()->table('service_staff')->count(),
            'booths' => $this->getConnection()->table('booths')->count(),
            'staff_shifts' => $this->getConnection()->table('staff_shifts')->count(),
            'customers' => Customer::query()->count(),
        ];
    }

    private function userCountWithRole(string $role): int
    {
        return $this->getConnection()
            ->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('roles.name', $role)
            ->count();
    }
}
