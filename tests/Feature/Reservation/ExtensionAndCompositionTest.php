<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Accounting\CheckoutEntryService;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Qualification;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 11-29: インターバル（終了後バッファ）、予約延長、予約メニューと実施施術（T/M/A）の分離。
 */
final class ExtensionAndCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Service $conditioning;

    private Service $training;

    private Service $massage;

    private Service $acupuncture;

    private Staff $staffA;

    private Staff $staffB;

    private Booth $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.allow_admin_free_time', false);
        app(Settings::class)->set('reservation.slot_minutes', 5, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '21:00');

        $this->conditioning = Service::factory()->create(['name' => 'ARKコンディショニング60', 'duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $this->training = Service::factory()->create(['name' => 'T', 'duration_min' => 30, 'requires_staff' => true, 'is_active' => true]);
        $this->massage = Service::factory()->create(['name' => 'M', 'duration_min' => 15, 'requires_staff' => true, 'is_active' => true]);
        $this->acupuncture = Service::factory()->create(['name' => 'A', 'duration_min' => 15, 'requires_staff' => true, 'is_active' => true]);
        $license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();
        $this->acupuncture->qualifications()->attach($license->id);
        $this->staffA = $this->staff('A');
        $this->staffB = $this->staff('B');
        $this->staffA->qualifications()->attach($license->id);
        foreach ([$this->conditioning, $this->training, $this->massage, $this->acupuncture] as $service) {
            $service->staff()->attach([$this->staffA->user_id, $this->staffB->user_id]);
        }
        $this->space = Booth::factory()->create(['name' => 'トレーニングA', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_interval_is_a_buffer_after_the_reservation_not_a_later_start(): void
    {
        $reservation = $this->book($this->staffA, '14:00', 5);

        $this->assertSame('14:00', $reservation->starts_at->format('H:i'));
        // 予約 14:00〜15:00、インターバル 15:00〜15:05（ends_at は占有の終わり）。
        $this->assertSame('15:05', $reservation->ends_at->format('H:i'));
        $this->assertSame(60, $reservation->bookedMinutes());

        $starts = array_column(app(AvailabilityService::class)->openStartTimes($this->conditioning->id, $this->staffA->user_id, null, CarbonImmutable::parse('2026-10-01')), 'starts_at');
        $this->assertNotContains('2026-10-01 15:00:00', $starts);
        $this->assertContains('2026-10-01 15:05:00', $starts);
        $this->assertRejectedOrConflict(fn () => $this->book($this->staffA, '15:00'));
        $this->assertSame('15:05', $this->book($this->staffA, '15:05')->starts_at->format('H:i'));
    }

    public function test_extension_adds_acupuncture_after_training_when_staff_and_space_are_free(): void
    {
        $reservation = $this->book($this->staffA, '14:00', 5);

        $extended = app(ReservationService::class)->extend((int) $reservation->id, 30, $this->acupuncture->id, (int) $reservation->version, null);

        // 予約 14:00〜15:30、インターバル 15:30〜15:35。
        $this->assertSame('15:35', $extended->ends_at->format('H:i'));
        $this->assertSame(90, $extended->bookedMinutes());
        $this->assertSame([[$this->acupuncture->id, 30]], $extended->segments()->get()->map(fn ($segment): array => [$segment->service_id, $segment->minutes])->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.extended']);
        // 延長分もスタッフ・ブースの枠として押さえる（15:30 にスタッフAは取れない）。
        $this->assertRejectedOrConflict(fn () => $this->book($this->staffA, '15:30'));
    }

    public function test_extension_is_refused_when_the_next_booking_or_its_buffer_would_overlap(): void
    {
        $reservation = $this->book($this->staffA, '14:00', 5);
        $this->book($this->staffA, '15:20');

        $this->assertRejectedOrConflict(fn () => app(ReservationService::class)->extend((int) $reservation->id, 30, $this->training->id, (int) $reservation->version, null));

        $fresh = $reservation->fresh();
        $this->assertSame('15:05', $fresh->ends_at->format('H:i'));
        $this->assertSame(0, $fresh->segments()->count());
        $this->assertSame((int) $reservation->version, (int) $fresh->version);
    }

    public function test_extension_with_acupuncture_is_refused_for_staff_without_the_license(): void
    {
        $reservation = $this->book($this->staffB, '14:00');

        try {
            app(ReservationService::class)->extend((int) $reservation->id, 30, $this->acupuncture->id, (int) $reservation->version, null);
            $this->fail('資格の無いスタッフではりを延長できてはならない');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('staff_id', $exception->errors());
        }
        $this->assertSame('15:00', $reservation->fresh()->ends_at->format('H:i'));
    }

    public function test_extension_is_refused_when_the_segment_service_is_inactive(): void
    {
        $reservation = $this->book($this->staffA, '14:00');
        $this->training->forceFill(['is_active' => false])->save();

        $this->assertValidationKey(
            fn () => app(ReservationService::class)->extend(
                (int) $reservation->id,
                30,
                (int) $this->training->id,
                (int) $reservation->version,
                null,
            ),
            'service_id',
        );
        $this->assertSame('15:00', $reservation->fresh()->ends_at->format('H:i'));
        $this->assertSame(0, $reservation->segments()->count());
    }

    public function test_actual_composition_within_the_reserved_time_follows_qualification_rules(): void
    {
        $reservation = $this->book($this->staffA, '14:00', 5);
        $entries = app(CheckoutEntryService::class);
        $visit = $entries->openForReservation($reservation, null);
        // 予約メニュー（コンディショニング60分）が下書きの施術として入る。
        $this->assertSame([[$this->conditioning->id, 60]], $visit->treatments()->get()->map(fn ($t): array => [$t->service_id, $t->actual_minutes])->all());

        foreach ([
            [[$this->training, 30], [$this->massage, 15], [$this->acupuncture, 15]],
            [[$this->training, 45], [$this->massage, 15]],
            [[$this->training, 60]],
        ] as $composition) {
            $entries->saveVisit($visit, $this->payload($composition, $this->staffA), null);
            $saved = $visit->treatments()->orderBy('sort_order')->get();
            $this->assertSame(60, (int) $saved->sum('actual_minutes'));
            $this->assertSame(array_map(fn (array $row): int => $row[0]->id, $composition), $saved->pluck('service_id')->all());
            $this->assertSame($this->space->id, $saved->first()->booth_id);
        }

        // 資格の無いスタッフBがはり（A）を担当する構成は保存できない。
        $this->assertValidationKey(fn () => $entries->saveVisit($visit, $this->payload([[$this->training, 45], [$this->acupuncture, 15]], $this->staffB), null), 'treatments.1.staff');
        // 予約の60分を超える構成は、延長してからでないと保存できない。
        $this->assertValidationKey(fn () => $entries->saveVisit($visit, $this->payload([[$this->training, 60], [$this->acupuncture, 30]], $this->staffA), null), 'treatments');
    }

    public function test_extended_reservation_prefills_the_planned_segments_and_accepts_90_minutes(): void
    {
        $reservation = $this->book($this->staffA, '14:00');
        $reservation = app(ReservationService::class)->extend((int) $reservation->id, 30, $this->acupuncture->id, (int) $reservation->version, null);
        $entries = app(CheckoutEntryService::class);
        $visit = $entries->openForReservation($reservation, null);

        $treatments = $visit->treatments()->orderBy('sort_order')->get();
        $this->assertSame([[$this->conditioning->id, 60], [$this->acupuncture->id, 30]], $treatments->map(fn ($t): array => [$t->service_id, $t->actual_minutes])->all());
        $entries->saveVisit($visit, $this->payload([[$this->training, 60], [$this->acupuncture, 30]], $this->staffA), null);
        $this->assertSame(90, (int) $visit->treatments()->sum('actual_minutes'));
    }

    public function test_extend_endpoint_requires_permission_and_reports_conflicts(): void
    {
        $reservation = $this->book($this->staffA, '14:00');
        $staffUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staffUser->assignRole('staff');
        $this->actingAs($staffUser)->post("/admin/reservations/{$reservation->id}/extend", ['minutes' => 30, 'version' => $reservation->version])->assertForbidden();

        $manager = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $manager->assignRole('manager');
        $this->actingAs($manager)->post("/admin/reservations/{$reservation->id}/extend", ['minutes' => 30, 'service_id' => $this->training->id, 'version' => $reservation->version])
            ->assertSessionHasNoErrors();
        $this->assertSame('15:30', $reservation->fresh()->ends_at->format('H:i'));
    }

    /** @param list<array{0: Service, 1: int}> $composition */
    private function payload(array $composition, Staff $staff): array
    {
        $cursor = CarbonImmutable::parse('2026-10-01 14:00');
        $treatments = [];
        foreach ($composition as [$service, $minutes]) {
            $treatments[] = ['service_id' => $service->id, 'actual_minutes' => $minutes, 'started_at' => $cursor->format('H:i'), 'booth_id' => $this->space->id,
                'staff' => [['staff_id' => $staff->user_id, 'actual_minutes' => $minutes]]];
            $cursor = $cursor->addMinutes($minutes);
        }

        return ['primary_staff_id' => $staff->user_id, 'nominated_staff_ids' => [], 'treatments' => $treatments, 'lines' => [], 'tenders' => []];
    }

    private function book(Staff $staff, string $time, int $buffer = 0): Reservation
    {
        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) Customer::factory()->create()->user_id,
            serviceId: (int) $this->conditioning->id,
            staffId: (int) $staff->user_id,
            boothId: $this->space->id ?? null,
            startsAt: CarbonImmutable::parse("2026-10-01 {$time}:00"),
            source: ReservationSource::Admin,
            actorUserId: null,
            notes: null,
            adminContext: true,
            bufferMin: $buffer,
        ));
    }

    private function staff(string $name): Staff
    {
        $staff = Staff::factory()->create(['display_name' => "担当{$name}", 'is_bookable' => true]);
        StaffShift::query()->create(['staff_id' => $staff->user_id, 'work_date' => '2026-10-01', 'start_at' => '10:00:00', 'end_at' => '21:00:00']);

        return $staff;
    }

    private function assertRejectedOrConflict(callable $action): void
    {
        try {
            $action();
            $this->fail('競合があるのに保存できてはならない');
        } catch (ValidationException|SlotUnavailableException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertValidationKey(callable $action, string $key): void
    {
        try {
            $action();
            $this->fail("{$key} で拒否されるべき");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
