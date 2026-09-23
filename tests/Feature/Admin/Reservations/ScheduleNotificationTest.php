<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 09:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_notifications_list_online_bookings_but_excludes_admin_entered_ones(): void
    {
        $admin = $this->admin();
        $service = Service::factory()->create(['name' => 'カット']);
        $customer = Customer::factory()->create();
        $customer->user->update(['name' => 'オンライン太郎']);

        $onlineReservation = $this->reservation(
            $customer,
            $service,
            ReservationSource::ArkWeb,
            '2026-09-14 08:00:00',
        );
        $this->reservation($customer, $service, ReservationSource::Admin, '2026-09-14 08:10:00');

        $payload = $this->actingAs($admin)
            ->getJson('/admin/schedule/notifications')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['notifications']);
        $this->assertSame($onlineReservation->id, $payload['notifications'][0]['id']);
        $this->assertSame('オンライン太郎', $payload['notifications'][0]['customer_name']);
    }

    public function test_dismissing_a_notification_hides_it_only_for_that_admin(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $service = Service::factory()->create();
        $customer = Customer::factory()->create();

        $reservation = $this->reservation($customer, $service, ReservationSource::ArkWeb, '2026-09-14 08:00:00');

        $this->actingAs($admin)
            ->postJson("/admin/schedule/notifications/{$reservation->id}/dismiss")
            ->assertOk();

        $forDismissingAdmin = $this->actingAs($admin)
            ->getJson('/admin/schedule/notifications')
            ->assertOk()
            ->json();
        $this->assertCount(0, $forDismissingAdmin['notifications']);

        $forOtherAdmin = $this->actingAs($otherAdmin)
            ->getJson('/admin/schedule/notifications')
            ->assertOk()
            ->json();
        $this->assertCount(1, $forOtherAdmin['notifications']);

        // 2回目の dismiss も冪等（firstOrCreate）でエラーにならない。
        $this->actingAs($admin)
            ->postJson("/admin/schedule/notifications/{$reservation->id}/dismiss")
            ->assertOk();
    }

    public function test_notifications_older_than_the_lookback_window_are_excluded(): void
    {
        $admin = $this->admin();
        $service = Service::factory()->create();
        $customer = Customer::factory()->create();

        $old = $this->reservation($customer, $service, ReservationSource::ArkWeb, '2026-09-14 08:00:00');
        $old->forceFill(['created_at' => CarbonImmutable::now()->subHours(30)])->save();

        $payload = $this->actingAs($admin)
            ->getJson('/admin/schedule/notifications')
            ->assertOk()
            ->json();

        $this->assertCount(0, $payload['notifications']);
    }

    private function reservation(
        Customer $customer,
        Service $service,
        ReservationSource $source,
        string $startsAt,
    ): Reservation {
        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes((int) $service->duration_min),
            'status' => ReservationStatus::Confirmed,
            'source' => $source,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
