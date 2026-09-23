<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Auth\Sms\FakeSmsSender;
use App\Domain\Auth\Sms\SmsSender;
use App\Models\Customer;
use App\Models\MfaSmsChallenge;
use App\Models\Reservation;
use App\Models\ReservationGuestToken;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class GuestReservationLookupTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '090-1234-5678';

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mfa.sms.resend.min_interval_seconds', 0);
        config()->set('mfa.sms.resend.max_per_hour', 100);
        config()->set('mfa.sms.rate_limit.send_per_ip_per_hour', 100);
        $sender = app(SmsSender::class);
        $this->assertInstanceOf(FakeSmsSender::class, $sender);
        $this->sms = $sender;
    }

    public function test_send_code_response_does_not_reveal_whether_the_phone_exists(): void
    {
        Customer::factory()->create(['phone' => self::PHONE]);

        $existing = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post(route('booking.find.sendCode'), ['phone' => self::PHONE]);
        $missing = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->post(route('booking.find.sendCode'), ['phone' => '080-9999-8888']);

        $existing->assertRedirect(route('booking.find.show'));
        $missing->assertRedirect(route('booking.find.show'));
        $existing->assertSessionHas(
            'success',
            '認証コードを送信しました。届いた6桁のコードを入力してください。',
        );
        $missing->assertSessionHas(
            'success',
            '認証コードを送信しました。届いた6桁のコードを入力してください。',
        );
        $this->assertSame(2, $this->sms->count());
    }

    public function test_correct_code_returns_all_reservations_across_matching_customers(): void
    {
        $service = Service::factory()->create();
        $firstCustomer = Customer::factory()->create(['phone' => self::PHONE]);
        $secondCustomer = Customer::factory()->create(['phone' => '09012345678']);
        $first = Reservation::factory()
            ->for($firstCustomer, 'customer')
            ->for($service)
            ->create(['booth_id' => null, 'starts_at' => now()->addDays(2)]);
        $second = Reservation::factory()
            ->for($secondCustomer, 'customer')
            ->for($service)
            ->create(['booth_id' => null, 'starts_at' => now()->addDay()]);

        $this->post(route('booking.find.sendCode'), ['phone' => self::PHONE])
            ->assertRedirect(route('booking.find.show'));
        $code = (string) $this->sms->lastCode();

        $this->post(route('booking.find.verify'), [
            'phone' => self::PHONE,
            'code' => $code,
        ])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Booking/FindResults')
            ->has('reservations', 2)
            ->where('reservations.0.id', $first->id)
            ->where('reservations.1.id', $second->id)
            ->where('reservations.0.service_name', $service->name));

        $this->assertDatabaseCount('reservation_guest_tokens', 2);
        $this->assertSame(
            2,
            ReservationGuestToken::query()
                ->whereIn('reservation_id', [$first->id, $second->id])
                ->count(),
        );
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->post(route('booking.find.sendCode'), ['phone' => self::PHONE]);

        $this->post(route('booking.find.verify'), [
            'phone' => self::PHONE,
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('reservation_guest_tokens', 0);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->post(route('booking.find.sendCode'), ['phone' => self::PHONE]);
        $code = (string) $this->sms->lastCode();
        MfaSmsChallenge::query()->sole()->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->post(route('booking.find.verify'), [
            'phone' => self::PHONE,
            'code' => $code,
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('reservation_guest_tokens', 0);
    }

    public function test_lookup_send_endpoint_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('booking.find.sendCode'), [
                'phone' => '090-1234-'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT),
            ])->assertRedirect(route('booking.find.show'));
        }

        $this->post(route('booking.find.sendCode'), ['phone' => '090-1234-9999'])
            ->assertStatus(429);
    }

    public function test_valid_code_with_no_matching_reservations_returns_an_empty_result(): void
    {
        $this->post(route('booking.find.sendCode'), ['phone' => self::PHONE]);
        $code = (string) $this->sms->lastCode();

        $this->post(route('booking.find.verify'), [
            'phone' => self::PHONE,
            'code' => $code,
        ])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Booking/FindResults')
            ->has('reservations', 0));
    }
}
