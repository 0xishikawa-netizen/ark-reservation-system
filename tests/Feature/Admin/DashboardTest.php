<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketWallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00:00'));
    }

    public function test_customer_cannot_view_admin_dashboard(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_sees_all_operational_sections_with_current_counts(): void
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $customerUser = User::factory()->create(['name' => '予約 顧客']);
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $service = Service::factory()->create(['name' => 'コンディショニング']);
        $staff = Staff::factory()->create(['display_name' => '担当 スタッフ']);
        $todayReservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => now()->addHour(),
            'status' => ReservationStatus::Confirmed,
        ]);

        Payment::factory()->create([
            'reservation_id' => $todayReservation->id,
            'customer_id' => $customer->user_id,
            'needs_attention' => true,
        ]);
        Membership::factory()->grace()->create();
        TicketWallet::factory()->create([
            'balance' => 2,
            'expires_at' => today()->addDays(14),
        ]);
        Reservation::factory()->create([
            'starts_at' => now()->addDay(),
            'status' => ReservationStatus::PendingPayment,
            'payment_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('today_reservation_count', 1)
                ->where('needs_attention_payment_count', 1)
                ->where('stale_pending_reservation_count', 1)
                ->where('membership_attention', [
                    'grace' => 1,
                    'paused' => 0,
                    'canceling' => 0,
                ])
                ->where('ticket_warning_count', 1)
                ->where('failed_jobs_count', 0)
                ->where('failedJobsCount', 0)
                ->has('next_arrivals', 1)
                ->where('next_arrivals.0.id', $todayReservation->id)
                ->where('next_arrivals.0.customer_name', '予約 顧客')
                ->where('next_arrivals.0.service_name', 'コンディショニング')
                ->where('next_arrivals.0.staff_name', '担当 スタッフ'));
    }

    public function test_staff_only_receives_sections_allowed_by_permissions(): void
    {
        $staffRole = Role::findByName('staff');
        $staffRole->syncPermissions(['admin.access', 'reservations.view']);

        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole($staffRole);

        Payment::factory()->create(['needs_attention' => true]);

        $this->actingAs($staff)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('today_reservation_count', 0)
                // reservations.view があるので予約系セクションは null にならない（中身は問わない）。
                ->whereNot('next_arrivals', null)
                ->where('needs_attention_payment_count', 1)
                ->where('stale_pending_reservation_count', 0)
                ->where('membership_attention', null)
                ->where('ticket_warning_count', null)
                ->where('failed_jobs_count', null));
    }
}
