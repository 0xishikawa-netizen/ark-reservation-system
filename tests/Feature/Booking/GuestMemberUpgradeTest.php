<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Reservation\GuestReservationTokenService;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class GuestMemberUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    public function test_placeholder_email_requires_a_real_email_and_upgrades_the_same_user(): void
    {
        [$reservation, $token, $customer, $user] = $this->guestReservation(
            'provisional+guest@ark.invalid',
        );
        $url = route('booking.confirmation.upgrade', ['selector' => $token]);

        $this->postJson($url, [
            'password' => 'GuestPassword123!',
            'password_confirmation' => 'GuestPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->post($url, [
            'email' => 'GUEST-MEMBER@example.com',
            'password' => 'GuestPassword123!',
            'password_confirmation' => 'GuestPassword123!',
        ])->assertRedirect(route('home'));

        $user->refresh();
        $this->assertSame('guest-member@example.com', $user->email);
        $this->assertTrue(Hash::check('GuestPassword123!', (string) $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, $customer->fresh()->user_id);
        $this->assertSame($customer->user_id, $reservation->fresh()->customer_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.guest_upgraded_to_member',
            'entity_id' => (string) $customer->user_id,
        ]);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_guest_with_a_real_email_can_keep_it(): void
    {
        [, $token, , $user] = $this->guestReservation('booking-email@example.com');

        $this->post(route('booking.confirmation.upgrade', ['selector' => $token]), [
            'password' => 'GuestPassword123!',
            'password_confirmation' => 'GuestPassword123!',
        ])->assertRedirect(route('home'));

        $this->assertSame('booking-email@example.com', $user->fresh()->email);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_collision_with_an_existing_member_is_rejected(): void
    {
        [, $token, , $guest] = $this->guestReservation('provisional+guest@ark.invalid');
        User::factory()->create(['email' => 'member@example.com']);

        $this->postJson(route('booking.confirmation.upgrade', ['selector' => $token]), [
            'email' => 'MEMBER@example.com',
            'password' => 'GuestPassword123!',
            'password_confirmation' => 'GuestPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertNull($guest->fresh()->password);
        Notification::assertNothingSent();
    }

    public function test_pre_upgrade_reservation_is_visible_in_the_member_mypage_without_query_changes(): void
    {
        [$reservation, $token, $customer, $user] = $this->guestReservation(
            'provisional+guest@ark.invalid',
        );

        $this->post(route('booking.confirmation.upgrade', ['selector' => $token]), [
            'email' => 'history@example.com',
            'password' => 'GuestPassword123!',
            'password_confirmation' => 'GuestPassword123!',
        ])->assertRedirect(route('home'));

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('mypage.reservations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customer/Reservations/Index')
                ->where('reservations.upcoming.0.id', $reservation->id));

        $this->assertSame($user->id, $customer->user_id);
        $this->assertSame($customer->user_id, $reservation->customer_id);
    }

    public function test_mismatched_selector_and_validator_cannot_upgrade_another_guest(): void
    {
        [, $tokenA, , $userA] = $this->guestReservation('provisional+a@ark.invalid');
        [, $tokenB, , $userB] = $this->guestReservation('provisional+b@ark.invalid');
        [$selectorA, $validatorA] = explode('.', $tokenA, 2);
        [$selectorB, $validatorB] = explode('.', $tokenB, 2);

        foreach (["{$selectorA}.{$validatorB}", "{$selectorB}.{$validatorA}"] as $index => $mixedToken) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($index + 30)]);
            $this->post(route('booking.confirmation.upgrade', ['selector' => $mixedToken]), [
                'email' => "attacker{$index}@example.com",
                'password' => 'GuestPassword123!',
                'password_confirmation' => 'GuestPassword123!',
            ])->assertNotFound();
        }

        $this->assertNull($userA->fresh()->password);
        $this->assertNull($userB->fresh()->password);
        Notification::assertNothingSent();
    }

    /** @return array{Reservation, string, Customer, User} */
    private function guestReservation(string $email): array
    {
        $customer = Customer::factory()->create();
        $user = $customer->user;
        $user->forceFill([
            'email' => $email,
            'email_verified_at' => null,
            'password' => null,
        ])->save();
        $user->assignRole('customer');
        $reservation = Reservation::factory()->for($customer, 'customer')->create([
            'staff_id' => null,
            'booth_id' => null,
            'starts_at' => now()->addWeek()->startOfHour(),
            'ends_at' => now()->addWeek()->startOfHour()->addHour(),
        ]);
        $token = app(GuestReservationTokenService::class)->issue($reservation);

        return [$reservation, $token, $customer, $user];
    }
}
