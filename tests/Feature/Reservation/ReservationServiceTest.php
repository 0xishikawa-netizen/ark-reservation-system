<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Reservation\RescheduleInput;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Models\AuditLog;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\StateMachine\Events\StateTransitioned;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_create_persists_confirmed_reservation_and_bulk_resource_slots(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();

        $reservation = $this->reservationService()->create($this->input(
            customer: $customer,
            service: $service,
            staff: $staff,
            booth: $booth,
        ));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(0, $reservation->version);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => ReservationStatus::Confirmed->value,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
        ]);
        $this->assertSame(8, $reservation->resourceSlots()->count());
        $this->assertSame(4, $reservation->resourceSlots()
            ->where('resource_type', ResourceType::Staff->value)->count());
        $this->assertSame(4, $reservation->resourceSlots()
            ->where('resource_type', ResourceType::Booth->value)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.created',
            'entity_id' => (string) $reservation->id,
            'summary' => "予約作成 #{$reservation->id} 2026-10-01 10:00",
        ]);
    }

    public function test_create_rejects_inactive_service(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $service->update(['is_active' => false]);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, $staff, $booth),
            ),
            'service_id',
        );
    }

    public function test_customer_create_rejects_service_that_is_not_online_bookable(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $service->update(['is_online_bookable' => false]);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, $staff, $booth),
            ),
            'service_id',
        );
    }

    public function test_create_requires_staff_when_service_requires_it(): void
    {
        [$customer, $service, , $booth] = $this->bookableMasters();

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, null, $booth),
            ),
            'staff_id',
        );
    }

    public function test_create_rejects_staff_not_assigned_to_service(): void
    {
        [$customer, $service, , $booth] = $this->bookableMasters();
        $otherStaff = Staff::factory()->create();

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, $otherStaff, $booth),
            ),
            'staff_id',
        );
    }

    public function test_create_rejects_staff_that_is_not_bookable(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $staff->update(['is_bookable' => false]);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, $staff, $booth),
            ),
            'staff_id',
        );
    }

    public function test_create_rejects_missing_or_partially_overlapping_staff_shift(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters(withShift: false);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, $staff, $booth),
            ),
            'starts_at',
        );

        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '10:30:00',
        ]);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, $staff, $booth),
            ),
            'starts_at',
        );
    }

    public function test_create_rejects_inactive_booth(): void
    {
        [$customer, $service, , $booth] = $this->bookableMasters(requiresStaff: false);
        $booth->update(['is_active' => false]);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, null, $booth),
            ),
            'booth_id',
        );
    }

    public function test_customer_create_rejects_past_time_but_admin_create_allows_it(): void
    {
        [$customer, $service, , $booth] = $this->bookableMasters(requiresStaff: false);
        $past = CarbonImmutable::parse('2026-08-01 10:00:00');

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, null, $booth, $past),
            ),
            'starts_at',
        );

        $reservation = $this->reservationService()->create(
            $this->input($customer, $service, null, $booth, $past, adminContext: true),
        );

        $this->assertSame('2026-08-01 10:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_create_requires_at_least_one_resource(): void
    {
        [$customer, $service] = $this->bookableMasters(requiresStaff: false);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create(
                $this->input($customer, $service, null, null),
            ),
            'resources',
        );
    }

    public function test_customer_create_rejects_non_boundary_start(): void
    {
        [$customer, $service, , $booth] = $this->bookableMasters(requiresStaff: false);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->create($this->input(
                $customer,
                $service,
                null,
                $booth,
                CarbonImmutable::parse('2026-10-01 10:07:00'),
            )),
            'starts_at',
        );
    }

    public function test_second_create_for_same_resource_slots_throws_conflict_and_rolls_back(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $input = $this->input($customer, $service, $staff, $booth);

        $first = $this->reservationService()->create($input);

        try {
            $this->reservationService()->create($input);
            $this->fail('同一スロットに二件目の予約が作成されました。');
        } catch (SlotUnavailableException $exception) {
            $this->assertSame('指定の時間帯は既に予約されています', $exception->getMessage());
        }

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(8, DB::table('reservation_resource_slots')->count());
        $this->assertDatabaseHas('reservations', ['id' => $first->id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'reservation.created')->count());
    }

    public function test_reschedule_replaces_slots_and_increments_version(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $reservation = $this->reservationService()->create(
            $this->input($customer, $service, $staff, $booth),
        );

        $reservation = $this->reservationService()->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: (int) $staff->user_id,
            boothId: (int) $booth->id,
            startsAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
            expectedVersion: 0,
            actorUserId: null,
            adminContext: false,
        ));

        $this->assertSame(1, $reservation->version);
        $this->assertSame('2026-10-01 12:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, DB::table('reservation_resource_slots')
            ->where('reservation_id', $reservation->id)
            ->where('slot_start', '2026-10-01 10:00:00')
            ->count());
        $this->assertSame(2, DB::table('reservation_resource_slots')
            ->where('reservation_id', $reservation->id)
            ->where('slot_start', '2026-10-01 12:00:00')
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.rescheduled',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_reschedule_rejects_stale_version(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $reservation = $this->reservationService()->create(
            $this->input($customer, $service, $staff, $booth),
        );

        $this->expectException(StaleReservationException::class);

        $this->reservationService()->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: (int) $staff->user_id,
            boothId: (int) $booth->id,
            startsAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
            expectedVersion: 1,
            actorUserId: null,
            adminContext: false,
        ));
    }

    public function test_reschedule_rejects_non_confirmed_reservation(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $reservation = $this->reservationService()->create(
            $this->input($customer, $service, $staff, $booth),
        );
        $this->reservationService()->markCompleted($reservation, null);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->reschedule(new RescheduleInput(
                reservationId: (int) $reservation->id,
                staffId: (int) $staff->user_id,
                boothId: (int) $booth->id,
                startsAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
                expectedVersion: 0,
                actorUserId: null,
                adminContext: false,
            )),
            'status',
        );
    }

    public function test_reschedule_conflict_rolls_back_and_preserves_original_slots(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $first = $this->reservationService()->create(
            $this->input($customer, $service, $staff, $booth),
        );
        $this->reservationService()->create($this->input(
            $customer,
            $service,
            $staff,
            $booth,
            CarbonImmutable::parse('2026-10-01 12:00:00'),
        ));

        try {
            $this->reservationService()->reschedule(new RescheduleInput(
                reservationId: (int) $first->id,
                staffId: (int) $staff->user_id,
                boothId: (int) $booth->id,
                startsAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
                expectedVersion: 0,
                actorUserId: null,
                adminContext: false,
            ));
            $this->fail('競合する時間帯へ予約を変更できました。');
        } catch (SlotUnavailableException) {
            $this->assertTrue(true);
        }

        $fresh = $first->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->version);
        $this->assertSame('2026-10-01 10:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, DB::table('reservation_resource_slots')
            ->where('reservation_id', $first->id)
            ->where('slot_start', '2026-10-01 10:00:00')
            ->count());
        $this->assertSame(16, DB::table('reservation_resource_slots')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'reservation.rescheduled')->count());
    }

    public function test_cancel_releases_slots_and_allows_same_slot_to_be_created_again(): void
    {
        Event::fake([StateTransitioned::class]);

        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $input = $this->input($customer, $service, $staff, $booth);
        $reservation = $this->reservationService()->create($input);

        $canceled = $this->reservationService()->cancel($reservation, '日程変更のため', null);

        $this->assertSame(ReservationStatus::Canceled, $canceled->status);
        $this->assertNotNull($canceled->canceled_at);
        $this->assertSame('日程変更のため', $canceled->cancel_reason);
        $this->assertSame(0, $canceled->resourceSlots()->count());
        Event::assertDispatched(
            StateTransitioned::class,
            fn (StateTransitioned $event): bool => $event->model->is($canceled)
                && $event->to === ReservationStatus::Canceled->value,
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.canceled',
            'entity_id' => (string) $canceled->id,
        ]);

        $replacement = $this->reservationService()->create($input);

        $this->assertNotSame($canceled->id, $replacement->id);
        $this->assertSame(8, $replacement->resourceSlots()->count());
    }

    public function test_cancel_rejects_already_canceled_reservation(): void
    {
        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $reservation = $this->reservationService()->create(
            $this->input($customer, $service, $staff, $booth),
        );
        $this->reservationService()->cancel($reservation, null, null);

        $this->assertValidationFailure(
            fn () => $this->reservationService()->cancel($reservation, null, null),
            'status',
        );
    }

    public function test_mark_completed_and_no_show_transition_status_and_keep_slots(): void
    {
        Event::fake([StateTransitioned::class]);

        [$customer, $service, $staff, $booth] = $this->bookableMasters();
        $completed = $this->reservationService()->create(
            $this->input($customer, $service, $staff, $booth),
        );
        $noShow = $this->reservationService()->create($this->input(
            $customer,
            $service,
            $staff,
            $booth,
            CarbonImmutable::parse('2026-10-01 12:00:00'),
        ));

        $completed = $this->reservationService()->markCompleted($completed, null);
        $noShow = $this->reservationService()->markNoShow($noShow, null);

        $this->assertSame(ReservationStatus::Completed, $completed->status);
        $this->assertNotNull($completed->attended_at);
        $this->assertSame(8, $completed->resourceSlots()->count());
        $this->assertSame(ReservationStatus::NoShow, $noShow->status);
        $this->assertNull($noShow->attended_at);
        $this->assertSame(8, $noShow->resourceSlots()->count());
        Event::assertDispatched(
            StateTransitioned::class,
            fn (StateTransitioned $event): bool => $event->model->is($completed)
                && $event->to === ReservationStatus::Completed->value,
        );
        Event::assertDispatched(
            StateTransitioned::class,
            fn (StateTransitioned $event): bool => $event->model->is($noShow)
                && $event->to === ReservationStatus::NoShow->value,
        );
        Event::assertDispatchedTimes(StateTransitioned::class, 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.completed',
            'entity_id' => (string) $completed->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.no_show',
            'entity_id' => (string) $noShow->id,
        ]);
    }

    public function test_conflict_exceptions_render_as_http_409_for_json_requests(): void
    {
        Route::middleware('web')->get(
            '/_test/reservation/slot-conflict',
            fn () => throw new SlotUnavailableException,
        );
        Route::middleware('web')->get(
            '/_test/reservation/stale-conflict',
            fn () => throw new StaleReservationException,
        );

        $this->getJson('/_test/reservation/slot-conflict')
            ->assertConflict()
            ->assertJsonPath('message', '指定の時間帯は既に予約されています')
            ->assertJsonValidationErrors('reservation');

        $this->getJson('/_test/reservation/stale-conflict')
            ->assertConflict()
            ->assertJsonPath('message', '予約が他で更新されました。画面を更新してください')
            ->assertJsonValidationErrors('reservation');
    }

    public function test_conflict_exception_returns_inertia_request_with_named_error_bag(): void
    {
        Route::middleware('web')->post(
            '/_test/reservation/inertia-conflict',
            fn () => throw new SlotUnavailableException,
        );

        $this->from('/admin/reservations')
            ->post('/_test/reservation/inertia-conflict', [], ['X-Inertia' => 'true'])
            ->assertRedirect('/admin/reservations')
            ->assertSessionHasErrorsIn('reservation', ['reservation']);
    }

    /**
     * @return array{Customer, Service, Staff, Booth}
     */
    private function bookableMasters(
        bool $requiresStaff = true,
        bool $withShift = true,
    ): array {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => $requiresStaff,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);

        $service->staff()->attach($staff->user_id);

        if ($withShift) {
            StaffShift::query()->create([
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '09:00:00',
                'end_at' => '18:00:00',
            ]);
        }

        return [$customer, $service, $staff, $booth];
    }

    private function input(
        Customer $customer,
        Service $service,
        ?Staff $staff,
        ?Booth $booth,
        ?CarbonImmutable $startsAt = null,
        bool $adminContext = false,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: $staff === null ? null : (int) $staff->user_id,
            boothId: $booth === null ? null : (int) $booth->id,
            startsAt: $startsAt ?? CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: $adminContext ? ReservationSource::Admin : ReservationSource::ArkWeb,
            actorUserId: null,
            notes: 'サービス動作テスト',
            adminContext: $adminContext,
        );
    }

    private function reservationService(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function assertValidationFailure(Closure $callback, string $key): void
    {
        try {
            $callback();
            $this->fail("{$key} の業務バリデーションが成功扱いになりました。");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
