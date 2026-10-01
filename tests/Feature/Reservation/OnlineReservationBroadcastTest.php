<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Broadcasting\ScheduleNotificationChannel;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Events\OnlineReservationCreated;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * オンライン予約通知のリアルタイム配信（§9-12）。
 * 実際のWebSocket配信自体はここでは検証しない（phpunit.xml で BROADCAST_CONNECTION=null
 * にしており、実配信は起きない）。ここで確認するのは、
 * 「どの経路の予約で発行されるか」「どの経路のペイロードになるか」「誰が購読できるか」という
 * アプリ側のロジックだけ。
 */
final class OnlineReservationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    // 予約日時を固定日付（2026-10-01）で書いているため、「現在」を固定して過去日扱いにならないようにする。
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 09:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_online_reservation_dispatches_broadcast_event_with_payload(): void
    {
        Event::fake([OnlineReservationCreated::class]);

        [$customer, $service, $staff, $booth] = $this->masters();

        $reservation = app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: (int) $booth->id,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: null,
            notes: null,
            adminContext: false,
        ));

        Event::assertDispatched(
            OnlineReservationCreated::class,
            fn (OnlineReservationCreated $event): bool => $event->payload['id'] === $reservation->id
                && $event->payload['source'] === ReservationSource::ArkWeb->value
                && $event->broadcastWhen() === true,
        );
    }

    public function test_admin_entered_reservation_event_does_not_broadcast(): void
    {
        // Admin 経由は「店舗スタッフの手入力」なのでオンライン予約通知の対象外（§9-12）。
        // イベント自体は発行されるが、broadcastWhen() が false になり実際には配信されない。
        Event::fake([OnlineReservationCreated::class]);

        [$customer, $service, $staff, $booth] = $this->masters();

        app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: (int) $booth->id,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::Admin,
            actorUserId: null,
            notes: null,
            adminContext: true,
        ));

        Event::assertDispatched(
            OnlineReservationCreated::class,
            fn (OnlineReservationCreated $event): bool => $event->broadcastWhen() === false
                && $event->payload === [],
        );
    }

    public function test_schedule_notifications_channel_authorizes_staff_with_reservations_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->assertTrue(ScheduleNotificationChannel::authorize($staff));
    }

    public function test_schedule_notifications_channel_rejects_user_without_reservations_view(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $customer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $customer->assignRole('customer');

        $this->assertFalse(ScheduleNotificationChannel::authorize($customer));
    }

    /** @return array{0: Customer, 1: Service, 2: Staff, 3: Booth} */
    private function masters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $service->staff()->attach($staff->user_id);

        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff, $booth];
    }
}
