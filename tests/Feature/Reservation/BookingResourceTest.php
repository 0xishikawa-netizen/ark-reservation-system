<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Qualification;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 11-28: メニュー×具体ブース、スタッフの施術可否・資格による予約可否。
 * 夫婦が同じ14:00から別スタッフ・別スペースでパーソナルを受けられること（Case A）と、
 * スタッフだけ／ブースだけ空いていても予約できないこと（Case B〜E）を確認する。
 */
final class BookingResourceTest extends TestCase
{
    use RefreshDatabase;

    private Service $personal;

    private Booth $trainingA;

    private Booth $trainingB;

    private Booth $bed;

    private Staff $staffA;

    private Staff $staffB;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.allow_admin_free_time', false);
        app(Settings::class)->set('reservation.slot_minutes', 5, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '21:00');

        $this->personal = Service::factory()->create(['name' => 'パーソナル60', 'duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $this->trainingA = Booth::factory()->create(['name' => 'トレーニングA', 'is_active' => true, 'sort_order' => 1]);
        $this->trainingB = Booth::factory()->create(['name' => 'トレーニングB', 'is_active' => true, 'sort_order' => 2]);
        $this->bed = Booth::factory()->create(['name' => 'ベッドA', 'is_active' => true, 'sort_order' => 3]);
        $this->personal->booths()->attach([$this->trainingA->id, $this->trainingB->id]);
        $this->staffA = $this->staff('A');
        $this->staffB = $this->staff('B');
        $this->personal->staff()->attach([$this->staffA->user_id, $this->staffB->user_id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_case_a_couple_books_two_personal_sessions_at_the_same_time_with_different_staff_and_spaces(): void
    {
        $husband = $this->book($this->personal, $this->staffA, null);
        // 1件目で紐付けブースの1つ目が埋まっても、2件目は空いている別の紐付けブースで取れる。
        $slots = app(AvailabilityService::class)->openStartTimes($this->personal->id, $this->staffB->user_id, null, CarbonImmutable::parse('2026-10-01'));
        $this->assertContains('2026-10-01 14:00:00', array_column($slots, 'starts_at'));
        $wife = $this->book($this->personal, $this->staffB, null);

        $this->assertSame($this->trainingA->id, $husband->booth_id);
        $this->assertSame($this->trainingB->id, $wife->booth_id);
        $this->assertSame('14:00', $wife->starts_at->format('H:i'));
        // 具体ブースも枠として押さえている（同時刻に同じブースは二重予約されない）。
        $this->assertSame(2, $husband->resourceSlots()->distinct('resource_type')->count('resource_type'));
    }

    public function test_case_b_second_booking_fails_when_every_mapped_space_is_taken_even_if_staff_b_is_free(): void
    {
        $this->book($this->personal, $this->staffA, $this->trainingA);
        $other = Service::factory()->create(['duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $staffC = $this->staff('C');
        $other->staff()->attach($staffC->user_id);
        $this->book($other, $staffC, $this->trainingB);

        $slots = app(AvailabilityService::class)->openStartTimes($this->personal->id, $this->staffB->user_id, null, CarbonImmutable::parse('2026-10-01'));
        $this->assertNotContains('2026-10-01 14:00:00', array_column($slots, 'starts_at'));
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffB, null), 'booth_id');
        // 別のブース（メニューに紐付いていないベッド）は空いていても使えない。
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffB, $this->bed), 'booth_id');
    }

    public function test_case_c_second_booking_fails_when_staff_b_is_off_shift_even_if_space_b_is_free(): void
    {
        $this->book($this->personal, $this->staffA, null);
        StaffShift::query()->where('staff_id', $this->staffB->user_id)->delete();

        $slots = app(AvailabilityService::class)->openStartTimes($this->personal->id, $this->staffB->user_id, null, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame([], $slots);
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffB, null), 'starts_at');
    }

    public function test_case_d_staff_who_cannot_perform_the_menu_is_rejected(): void
    {
        $staffC = $this->staff('C');

        $slots = app(AvailabilityService::class)->openStartTimes($this->personal->id, $staffC->user_id, null, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame([], $slots);
        $this->assertRejected(fn () => $this->book($this->personal, $staffC, null), 'staff_id');
    }

    public function test_case_e_acupuncture_requires_the_qualification_and_is_not_hardcoded_by_name(): void
    {
        $acupuncture = Service::factory()->create(['name' => 'はり15', 'duration_min' => 15, 'requires_staff' => true, 'is_active' => true]);
        $acupuncture->booths()->attach($this->bed->id);
        $acupuncture->staff()->attach([$this->staffA->user_id, $this->staffB->user_id]);
        $license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();
        $acupuncture->qualifications()->attach($license->id);
        $this->staffA->qualifications()->attach($license->id);

        $date = CarbonImmutable::parse('2026-10-01');
        $available = app(AvailabilityService::class)->openStartTimes($acupuncture->id, null, null, $date);
        $this->assertNotEmpty($available);
        foreach ($available as $slot) {
            // 資格の無いスタッフBは候補に出ない。
            $this->assertSame([$this->staffA->user_id], $slot['available_staff_ids']);
        }
        $this->assertSame([], app(AvailabilityService::class)->openStartTimes($acupuncture->id, $this->staffB->user_id, null, $date));
        $this->assertRejected(fn () => $this->book($acupuncture, $this->staffB, null), 'staff_id');

        $booked = $this->book($acupuncture, $this->staffA, null);
        $this->assertSame($this->bed->id, $booked->booth_id);
    }

    public function test_menu_without_booth_mapping_keeps_the_previous_optional_booth_behaviour(): void
    {
        $plain = Service::factory()->create(['duration_min' => 30, 'requires_staff' => true, 'is_active' => true]);
        $plain->staff()->attach($this->staffA->user_id);

        $reservation = $this->book($plain, $this->staffA, null);
        $this->assertNull($reservation->booth_id);
        $this->assertSame($this->bed->id, $this->book($plain, $this->staffA, $this->bed, '16:00')->booth_id);
    }

    public function test_reschedule_keeps_the_buffer_and_moves_to_a_free_mapped_space(): void
    {
        $first = $this->book($this->personal, $this->staffA, null, '14:00', 5);
        $this->assertSame('15:05', $first->ends_at->format('H:i'));
        $other = $this->book($this->personal, $this->staffB, null, '16:00');
        $this->assertSame($this->trainingA->id, $other->booth_id);

        $moved = app(ReservationService::class)->reschedule(new RescheduleInput(
            reservationId: (int) $first->id,
            staffId: (int) $this->staffA->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 16:00:00'),
            expectedVersion: (int) $first->version,
            actorUserId: null,
            adminContext: true,
        ));

        $this->assertSame('16:00', $moved->starts_at->format('H:i'));
        // 終了後バッファ5分を保ったまま移動する（以前は変更でバッファが消えていた）。
        $this->assertSame('17:05', $moved->ends_at->format('H:i'));
        $this->assertSame(5, (int) $moved->buffer_min);
        // 16:00 はトレーニングAがスタッフBの予約で埋まっているため、空いているトレーニングBへ確定する。
        $this->assertSame($this->trainingB->id, $moved->booth_id);
    }

    private function book(Service $service, Staff $staff, ?Booth $booth, string $time = '14:00', int $buffer = 0): Reservation
    {
        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) Customer::factory()->create()->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: $booth?->id,
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

    private function assertRejected(callable $book, string $field): void
    {
        try {
            $book();
            $this->fail("予約できてはならない（{$field}）");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
