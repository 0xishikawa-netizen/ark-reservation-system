<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Jobs\FailedJobsReader;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FailedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_and_staff_without_permission_cannot_view_failed_jobs(): void
    {
        foreach (['customer', 'staff'] as $role) {
            $user = User::factory()->create([
                'two_factor_confirmed_at' => now(),
            ]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->get('/admin/system/failed-jobs')
                ->assertForbidden();
        }
    }

    public function test_manager_can_view_failed_job_details(): void
    {
        $manager = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $manager->assignRole('manager');
        $uuid = $this->insertFailedJob([
            'connection' => 'database',
            'queue' => 'notifications',
            'exception' => "Expected failure\nStack trace",
        ]);

        $this->actingAs($manager)
            ->get('/admin/system/failed-jobs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/System/FailedJobs')
                ->where('count', 1)
                ->has('jobs.data', 1)
                ->where('jobs.data.0.uuid', $uuid)
                ->where('jobs.data.0.connection', 'database')
                ->where('jobs.data.0.queue', 'notifications')
                ->where('jobs.data.0.exception_first_line', 'Expected failure'));
    }

    public function test_admin_can_view_failed_jobs(): void
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/system/failed-jobs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/System/FailedJobs'));
    }

    public function test_dashboard_displays_the_actual_failed_jobs_count(): void
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');
        $this->insertFailedJob();
        $this->insertFailedJob();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('failedJobsCount', 2));
    }

    public function test_reader_counts_and_formats_failed_jobs_in_latest_order(): void
    {
        $this->insertFailedJob([
            'exception' => "Older failure\nOlder trace",
            'failed_at' => now()->subMinute(),
        ]);
        $firstLine = str_repeat('あ', 301);
        $newestUuid = $this->insertFailedJob([
            'exception' => $firstLine."\r\nNewer trace",
            'failed_at' => now(),
        ]);

        $reader = app(FailedJobsReader::class);
        $jobs = $reader->latest();
        $firstJob = $jobs->items()[0];

        $this->assertSame(2, $reader->count());
        $this->assertSame($newestUuid, $firstJob['uuid']);
        $this->assertSame(Str::substr($firstLine, 0, 300), $firstJob['exception_first_line']);
        $this->assertArrayNotHasKey('exception', $firstJob);
    }

    /** @param array<string, mixed> $overrides */
    private function insertFailedJob(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => "RuntimeException: failed\nStack trace",
            'failed_at' => now(),
            ...$overrides,
        ]);

        return $uuid;
    }
}
