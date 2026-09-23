<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Reservation\GuestReservationTokenService;
use App\Models\Reservation;
use App\Models\ReservationGuestToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GuestReservationTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_token_resolves_its_reservation_and_updates_last_used_at(): void
    {
        $reservation = Reservation::factory()->create();
        $service = app(GuestReservationTokenService::class);
        [$selector, $validator] = explode('.', $service->issue($reservation), 2);

        $this->assertNull(ReservationGuestToken::query()->firstOrFail()->last_used_at);

        $resolved = $service->resolve($selector, $validator);

        $this->assertSame($reservation->id, $resolved?->id);
        $this->assertNotNull(ReservationGuestToken::query()->firstOrFail()->last_used_at);
    }

    public function test_wrong_validator_does_not_resolve_a_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        $service = app(GuestReservationTokenService::class);
        [$selector] = explode('.', $service->issue($reservation), 2);

        $this->assertNull($service->resolve($selector, 'wrong-validator'));
    }

    public function test_another_reservations_token_only_resolves_that_reservation(): void
    {
        $expected = Reservation::factory()->create();
        $unrelated = Reservation::factory()->create();
        $service = app(GuestReservationTokenService::class);
        [$expectedSelector, $expectedValidator] = explode('.', $service->issue($expected), 2);
        [$unrelatedSelector, $unrelatedValidator] = explode('.', $service->issue($unrelated), 2);

        $this->assertSame($expected->id, $service->resolve($expectedSelector, $expectedValidator)?->id);
        $this->assertNull($service->resolve($expectedSelector, $unrelatedValidator));

        $resolved = $service->resolve($unrelatedSelector, $unrelatedValidator);
        $this->assertNotSame($expected->id, $resolved?->id);
        $this->assertSame($unrelated->id, $resolved?->id);
    }

    public function test_expired_token_does_not_resolve_a_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        $service = app(GuestReservationTokenService::class);
        [$selector, $validator] = explode('.', $service->issue($reservation), 2);
        ReservationGuestToken::query()->update(['expires_at' => now()->subSecond()]);

        $this->assertNull($service->resolve($selector, $validator));
    }
}
