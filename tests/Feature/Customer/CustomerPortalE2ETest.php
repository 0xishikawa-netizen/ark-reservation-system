<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 7 Customer Portal の代表フロー（HTTP 経由）。
 */
final class CustomerPortalE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->seed(RolePermissionSeeder::class);
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        return $customer;
    }

    /** @return array{Service, Staff} */
    private function bookable(): array
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$service, $staff];
    }

    // Flow A: login → dashboard → reserve(onsite) → confirmed → reservation detail
    public function test_flow_a_onsite_booking_from_portal(): void
    {
        $customer = $this->customer();
        [$service, $staff] = $this->bookable();

        // dashboard
        $this->actingAs($customer->user)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Customer/Dashboard')->where('next_reservation', null));

        // reserve wizard
        $this->actingAs($customer->user)->get('/reserve')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Customer/Reserve/Index'));

        // store (onsite)
        $response = $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'onsite',
        ]);
        $response->assertRedirect();

        $reservation = $customer->user->customer->refresh()
            ? \App\Models\Reservation::query()->where('customer_id', $customer->user_id)->firstOrFail()
            : null;
        $this->assertNotNull($reservation);
        $this->assertSame('confirmed', $reservation->status->value);
        $this->assertSame('onsite', $reservation->payment_method->value);

        // detail
        $this->actingAs($customer->user)->get("/mypage/reservations/{$reservation->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Customer/Reservations/Show')
                ->where('reservation.status', 'confirmed')
                ->where('reservation.payment_method', 'onsite'));

        // dashboard now shows the next reservation
        $this->actingAs($customer->user)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('next_reservation.id', $reservation->id)
                ->where('upcoming_count', 1));
    }

    // Flow E: reservation history shows only own
    public function test_flow_e_history_is_own_only(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        [$service, $staff] = $this->bookable();

        \App\Models\Reservation::factory()->create([
            'customer_id' => $me->user_id, 'starts_at' => now()->subDays(3), 'status' => 'completed',
        ]);
        \App\Models\Reservation::factory()->create([
            'customer_id' => $other->user_id, 'starts_at' => now()->subDays(3), 'status' => 'completed',
        ]);

        $this->actingAs($me->user)->get('/mypage/reservations')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reservations.past', fn ($rows) => count($rows) === 1));
    }

    // Flow: payment history page renders for a customer with no payments (empty state)
    public function test_payment_history_empty_state_renders(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer->user)->get('/mypage/payments')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Customer/Payments/Index')
                ->where('payments', []));
    }
}
