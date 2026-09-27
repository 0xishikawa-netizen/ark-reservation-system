<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\CheckoutExemptionReason;
use App\Models\Booth;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Queries\DailyReportQuery;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminReservationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.allow_admin_free_time', false);
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '18:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_staff_can_view_schedule_but_cannot_create_reservations(): void
    {
        $staff = $this->roleUser('staff');

        $this->actingAs($staff)
            ->get('/admin/schedule?date=2026-10-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Schedule/Index')
                ->where('auth.can.reservationsView', true)
                ->where('auth.can.reservationsManage', false));
        // 新規予約の専用画面は廃止したため、同じ権限で守られたAPIで確認する。
        $this->actingAs($staff)
            ->get('/admin/reservations/availability?service_id=1&date=2026-10-01')
            ->assertForbidden();
        $this->actingAs($staff)
            ->postJson('/admin/reservations', [])
            ->assertForbidden();
    }

    public function test_manager_and_admin_can_create_admin_source_reservation_with_slots_and_audit(): void
    {
        foreach (['manager', 'admin'] as $role) {
            [$customer, $service, $staff, $booth] = $this->masters("{$role}-create");
            $actor = $this->roleUser($role);

            $this->actingAs($actor)
                ->post('/admin/reservations', [
                    'customer_id' => $customer->user_id,
                    'service_id' => $service->id,
                    'staff_id' => $staff->user_id,
                    'booth_id' => $booth->id,
                    'starts_at' => '2026-10-01 10:00:00',
                    'notes' => '受付で確認',
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('admin.schedule.index', ['date' => '2026-10-01']));

            $reservation = Reservation::query()
                ->where('customer_id', $customer->user_id)
                ->firstOrFail();
            $this->assertSame(ReservationSource::Admin, $reservation->source);
            $this->assertSame(8, $reservation->resourceSlots()->count());
            $this->assertDatabaseHas('audit_logs', [
                'actor_user_id' => $actor->id,
                'action' => 'reservation.created',
                'entity_id' => (string) $reservation->id,
            ]);
        }
    }

    public function test_inactive_masters_are_rejected_with_422(): void
    {
        $manager = $this->roleUser('manager');
        [$customer, $service, $staff, $booth] = $this->masters('inactive');

        $service->update(['is_active' => false]);
        $this->actingAs($manager)->postJson('/admin/reservations', $this->payload(
            $customer,
            $service,
            $staff,
            $booth,
        ))->assertUnprocessable()->assertJsonValidationErrors('service_id');
        $service->update(['is_active' => true]);

        $staff->update(['is_bookable' => false]);
        $this->actingAs($manager)->postJson('/admin/reservations', $this->payload(
            $customer,
            $service,
            $staff,
            $booth,
        ))->assertUnprocessable()->assertJsonValidationErrors('staff_id');
        $staff->update(['is_bookable' => true]);

        $booth->update(['is_active' => false]);
        $this->actingAs($manager)->postJson('/admin/reservations', $this->payload(
            $customer,
            $service,
            $staff,
            $booth,
        ))->assertUnprocessable()->assertJsonValidationErrors('booth_id');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_admin_past_start_succeeds_but_non_boundary_is_422_when_free_time_is_disabled(): void
    {
        $manager = $this->roleUser('manager');
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => false,
            'is_active' => true,
        ]);
        $booth = Booth::factory()->create(['is_active' => true]);

        $this->actingAs($manager)->post('/admin/reservations', [
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => null,
            'booth_id' => $booth->id,
            'starts_at' => '2026-08-01 10:00:00',
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->postJson('/admin/reservations', [
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => null,
            'booth_id' => $booth->id,
            'starts_at' => '2026-08-02 10:07:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_reschedule_changes_time_and_increments_version_and_stale_version_is_409(): void
    {
        $manager = $this->roleUser('manager');
        [$customer, $service, $staff, $booth] = $this->masters('reschedule');
        $reservation = $this->createThroughService($customer, $service, $staff, $booth, $manager);

        $this->actingAs($manager)->put("/admin/reservations/{$reservation->id}", [
            'starts_at' => '2026-10-01 12:00:00',
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'version' => 0,
            'notes' => '日時変更と同時に更新',
        ])->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('2026-10-01 12:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $reservation->version);
        $this->assertSame('日時変更と同時に更新', $reservation->notes);

        $this->actingAs($manager)->putJson("/admin/reservations/{$reservation->id}", [
            'starts_at' => '2026-10-01 13:00:00',
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'version' => 0,
            'notes' => null,
        ])->assertConflict()->assertJsonPath(
            'errors.reservation.0',
            '予約が他で更新されました。画面を更新してください',
        );
    }

    public function test_notes_only_update_uses_action_and_checks_version(): void
    {
        $manager = $this->roleUser('manager');
        [$customer, $service, $staff, $booth] = $this->masters('notes');
        $reservation = $this->createThroughService($customer, $service, $staff, $booth, $manager);

        $this->actingAs($manager)->put("/admin/reservations/{$reservation->id}", [
            'starts_at' => '2026-10-01 10:00:00',
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'version' => 0,
            'notes' => '持参物を確認する',
        ])->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertSame('持参物を確認する', $reservation->notes);
        $this->assertSame(1, $reservation->version);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'reservation.notes_updated',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_cancel_releases_slots_while_complete_and_no_show_keep_them(): void
    {
        $manager = $this->roleUser('manager');
        [$customer, $service, $staff, $booth] = $this->masters('status');
        $canceled = $this->createThroughService($customer, $service, $staff, $booth, $manager, '10:00:00');
        $completed = $this->createThroughService($customer, $service, $staff, $booth, $manager, '12:00:00');
        $noShow = $this->createThroughService($customer, $service, $staff, $booth, $manager, '14:00:00');

        $this->actingAs($manager)
            ->patch("/admin/reservations/{$canceled->id}/cancel", ['reason' => '店舗都合'])
            ->assertSessionHasNoErrors();
        $this->actingAs($manager)
            ->patch("/admin/reservations/{$completed->id}/complete", ['exemption_reason' => 'free'])
            ->assertSessionHasNoErrors();
        // ダブルクリック／HTTP再送でも既存の完了結果へ収束する。
        $this->actingAs($manager)
            ->patch("/admin/reservations/{$completed->id}/complete", ['exemption_reason' => 'free'])
            ->assertSessionHasNoErrors();
        $this->actingAs($manager)
            ->patch("/admin/reservations/{$noShow->id}/no-show")
            ->assertSessionHasNoErrors();

        $this->assertSame(ReservationStatus::Canceled, $canceled->fresh()?->status);
        $this->assertSame(0, $canceled->resourceSlots()->count());
        $this->assertSame(ReservationStatus::Completed, $completed->fresh()?->status);
        $this->assertNotNull($completed->fresh()?->attended_at);
        $this->assertSame(1, $completed->visit()->count());
        $this->assertSame(8, $completed->resourceSlots()->count());
        $this->assertSame(ReservationStatus::NoShow, $noShow->fresh()?->status);
        $this->assertSame(8, $noShow->resourceSlots()->count());
    }

    public function test_fixed_reservation_routes_are_not_captured_by_model_binding(): void
    {
        $manager = $this->roleUser('manager');
        [$customer, $service, $staff, $booth] = $this->masters('routes');

        $this->actingAs($manager)
            ->getJson('/admin/reservations/customer-search?q='.urlencode($customer->user->name))
            ->assertOk();
        $this->actingAs($manager)
            ->getJson('/admin/reservations/availability?'.http_build_query([
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'booth_id' => $booth->id,
                'date' => '2026-10-01',
            ]))
            ->assertOk();
        // 予約の作成はブッキングボード（Admin/Schedule/Index）のパネルから行う。
        $this->actingAs($manager)
            ->get('/admin/schedule?date=2026-10-01&panel=create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Schedule/Index'));
    }

    /** @return array{Customer, Service, Staff, Booth} */
    /**
     * Task 11-27: 会計を作らない来店完了は、理由（無料・事前決済済み・回数券/月額利用）を明示した時だけ。
     * 理由があれば来店に残り、日計の「会計未確定」に数えない。
     */
    public function test_completion_without_checkout_requires_an_explicit_reason(): void
    {
        $manager = $this->roleUser('manager');
        [$customer, $service, $staff, $booth] = $this->masters('exemption');
        $reservation = $this->createThroughService($customer, $service, $staff, $booth, $manager, '10:00:00');

        $this->actingAs($manager)
            ->patch("/admin/reservations/{$reservation->id}/complete")
            ->assertSessionHasErrors('exemption_reason');
        $this->actingAs($manager)
            ->patch("/admin/reservations/{$reservation->id}/complete", ['exemption_reason' => 'paid_somehow'])
            ->assertSessionHasErrors('exemption_reason');
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()?->status);
        $this->assertSame(0, $reservation->visit()->count());

        $this->actingAs($manager)
            ->patch("/admin/reservations/{$reservation->id}/complete", ['exemption_reason' => 'prepaid'])
            ->assertSessionHasNoErrors();
        $visit = $reservation->visit()->firstOrFail();
        $this->assertSame(CheckoutExemptionReason::Prepaid, $visit->checkout_exemption_reason);
        $this->assertSame(0, Checkout::query()->where('visit_id', $visit->id)->count());
        $daily = app(DailyReportQuery::class)->fetch(CarbonImmutable::parse($visit->business_date->toDateString(), 'Asia/Tokyo'));
        $this->assertSame(1, $daily['visits']['visit_count']);
        $this->assertSame(0, $daily['visits']['accounting_pending_visit_count']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.completed']);
    }

    private function masters(string $suffix): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'name' => "service-{$suffix}",
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
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

    /** @return array<string, int|string|null> */
    private function payload(
        Customer $customer,
        Service $service,
        Staff $staff,
        Booth $booth,
    ): array {
        return [
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'starts_at' => '2026-10-01 10:00:00',
        ];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function createThroughService(
        Customer $customer,
        Service $service,
        Staff $staff,
        Booth $booth,
        User $actor,
        string $time = '10:00:00',
    ): Reservation {
        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: (int) $booth->id,
            startsAt: CarbonImmutable::parse("2026-10-01 {$time}"),
            source: ReservationSource::Admin,
            actorUserId: (int) $actor->id,
            notes: null,
            adminContext: true,
        ));
    }
}
