<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MyReservationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_customer_can_view_only_their_own_list_and_detail(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $own = $this->createReservation($customer, $service, $staff, '2026-10-01 10:00:00');
        $other = $this->createReservation($otherCustomer, $service, $staff, '2026-10-01 12:00:00');

        $this->actingAs($customer->user)
            ->get('/mypage/reservations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Reservations/Index')
                ->has('reservations.upcoming', 1)
                ->where('reservations.upcoming.0.id', $own->id));

        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$own->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Reservations/Show')
                ->where('reservation.id', $own->id)
                ->where('reservation.version', 0)
                ->where('reservation.can_cancel', true));

        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$other->id}")
            ->assertForbidden();
    }

    public function test_customer_can_cancel_own_confirmed_reservation_and_rebook_released_slot(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = $this->createReservation(
            $customer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );
        $this->assertSame(4, $reservation->resourceSlots()->count());

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$reservation->id}", [
                'reason' => '都合が悪くなったため',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.reservations.index'));

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Canceled, $reservation->status);
        $this->assertSame('都合が悪くなったため', $reservation->cancel_reason);
        $this->assertSame(0, $reservation->resourceSlots()->count());

        $replacement = $this->createReservation(
            $customer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );
        $this->assertSame(ReservationStatus::Confirmed, $replacement->status);
    }

    public function test_customer_cannot_cancel_another_customers_reservation(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = $this->createReservation(
            $otherCustomer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$reservation->id}")
            ->assertForbidden();

        $this->assertSame(
            ReservationStatus::Confirmed,
            $reservation->fresh()?->status,
        );
    }

    public function test_customer_can_reschedule_with_matching_version_and_stale_version_is_409(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = $this->createReservation(
            $customer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );

        $this->actingAs($customer->user)
            ->put("/mypage/reservations/{$reservation->id}", [
                'staff_id' => null,
                'starts_at' => '2026-10-01 12:00:00',
                'version' => 0,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.reservations.show', $reservation));

        $reservation->refresh();
        $this->assertSame('2026-10-01 12:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $reservation->version);

        $this->actingAs($customer->user)
            ->putJson("/mypage/reservations/{$reservation->id}", [
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 14:00:00',
                'version' => 0,
            ])
            ->assertConflict()
            ->assertJsonPath('errors.reservation.0', '予約が他で更新されました。画面を更新してください');
    }

    public function test_customer_cannot_reschedule_another_customers_reservation(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = $this->createReservation(
            $otherCustomer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );

        $this->actingAs($customer->user)
            ->put("/mypage/reservations/{$reservation->id}", [
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 12:00:00',
                'version' => 0,
            ])
            ->assertForbidden();
    }

    public function test_customer_cannot_change_or_cancel_a_started_reservation(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => '2026-08-01 10:00:00',
            'ends_at' => '2026-08-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $this->actingAs($customer->user)
            ->putJson("/mypage/reservations/{$reservation->id}", [
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 12:00:00',
                'version' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->actingAs($customer->user)
            ->deleteJson("/mypage/reservations/{$reservation->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertSame(
            ReservationStatus::Confirmed,
            $reservation->fresh()?->status,
        );
    }

    public function test_eleventh_reschedule_attempt_is_rate_limited(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = $this->createReservation(
            $customer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($customer->user)
                ->putJson("/mypage/reservations/{$reservation->id}", [])
                ->assertUnprocessable();
        }

        $this->actingAs($customer->user)
            ->putJson("/mypage/reservations/{$reservation->id}", [])
            ->assertTooManyRequests();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/mypage/reservations')
            ->assertRedirect(route('login'));
    }

    /** @return array{Service, Staff} */
    private function bookableServiceAndStaff(): array
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

    private function createReservation(
        Customer $customer,
        Service $service,
        Staff $staff,
        string $startsAt,
    ): Reservation {
        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse($startsAt),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
        ));
    }
}
