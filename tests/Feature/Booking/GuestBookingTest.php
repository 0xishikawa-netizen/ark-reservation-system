<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Models\Booth;
use App\Models\Reservation;
use App\Models\ReservationGuestToken;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Notifications\GuestReservationConfirmed;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class GuestBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        $settings = app(Settings::class);
        $settings->set('reservation.slot_minutes', 15, 'int');
        $settings->set('business_hours.open', '10:00');
        $settings->set('business_hours.close', '18:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_guest_can_book_without_auth_and_malicious_source_is_stored_as_direct(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();

        $this->get('/booking')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Booking/Index')
                ->where('services.0.id', $service->id));

        $this->getJson('/booking/availability?'.http_build_query([
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'date' => '2026-10-01',
        ]))
            ->assertOk()
            ->assertJsonPath('0.starts_at', '2026-10-01 10:00:00');

        $response = $this->post('/booking', [
            'name' => 'ゲスト 予約',
            'phone' => '090-1234-5678',
            'email' => 'guest-booking@example.com',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'onsite',
            'notes' => '入口付近を希望',
            'source' => '<script>alert(1)</script>',
        ]);

        $reservation = Reservation::query()->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringContainsString('/booking/confirmation/', (string) $response->headers->get('Location'));
        $this->assertSame(ReservationSource::ArkWeb, $reservation->source);
        $this->assertSame('direct', $reservation->inflow_channel);
        $this->assertNotSame('<script>alert(1)</script>', $reservation->inflow_channel);
        $this->assertSame(PaymentMethod::Onsite, $reservation->payment_method);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->created_by);
        $this->assertDatabaseCount('reservation_guest_tokens', 1);
        Notification::assertSentTo(
            $reservation->customer->user,
            GuestReservationConfirmed::class,
        );

        $confirmationPath = (string) parse_url(
            (string) $response->headers->get('Location'),
            PHP_URL_PATH,
        );
        $confirmation = $this->get($confirmationPath)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Booking/Confirmation')
                ->where('reservation.id', $reservation->id)
                ->where('reservation.service_name', $service->name)
                ->where('reservation.staff_name', $staff->display_name)
                ->where('reservation.status', 'confirmed')
                ->where('reservation.payment_status', 'unpaid')
                ->missing('reservation.email')
                ->missing('reservation.phone'));

        $this->assertNotNull(ReservationGuestToken::query()->firstOrFail()->last_used_at);
    }

    public function test_guest_booking_assigns_an_available_booth_and_reserves_its_slots(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        $booth = Booth::factory()->create(['is_active' => true]);

        $this->post('/booking', [
            'name' => 'ゲスト ブース予約',
            'phone' => '09012345678',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'onsite',
        ])->assertSessionHasNoErrors();

        $reservation = Reservation::query()->sole();

        $this->assertNotNull($reservation->booth_id);
        $this->assertSame($booth->id, $reservation->booth_id);
        $this->assertDatabaseHas('reservation_resource_slots', [
            'reservation_id' => $reservation->id,
            'resource_type' => ResourceType::Booth->value,
            'resource_id' => $booth->id,
        ]);
    }

    public function test_guest_payment_method_rejects_member_only_options(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();

        $this->postJson('/booking', [
            'name' => 'ゲスト 予約',
            'phone' => '09012345678',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'ticket',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_single_payment_booking_redirects_to_guest_checkout(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();

        $response = $this->post('/booking', [
            'name' => 'ゲスト 決済',
            'phone' => '09012345678',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'single',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringContainsString(
            '/checkout',
            (string) $response->headers->get('Location'),
        );
        $this->assertSame(
            ReservationStatus::PendingPayment,
            Reservation::query()->sole()->status,
        );
        Notification::assertNothingSent();
    }

    public function test_existing_member_email_is_rejected_by_public_booking_endpoint(): void
    {
        [$service, $staff] = $this->bookableServiceAndStaff();
        User::factory()->create(['email' => 'member@example.com']);

        $this->postJson('/booking', [
            'name' => 'ゲスト 予約',
            'phone' => '09012345678',
            'email' => 'member@example.com',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'onsite',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath(
                'errors.email.0',
                'このメールアドレスは登録済みです。ログインして予約してください。',
            );

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_invalid_confirmation_token_returns_the_same_not_found_response(): void
    {
        $this->getJson('/booking/confirmation/unknown.invalid')
            ->assertNotFound();
        $this->getJson('/booking/confirmation/malformed')
            ->assertNotFound();
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
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$service, $staff];
    }
}
