<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Accounting\CheckoutEntryService;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ResourceType;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Qualification;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Queries\ReservationFormOptionsQuery;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 全面検証（docs/testing/2026-09-27-full-verification.md）で不足していた予約系ケース。
 * UT-B / UT-S / UT-Q / UT-I / UT-RS / UT-E、IT-R、空き枠API、時間変更の再計算を確認する。
 */
final class BookingVerificationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Service $personal;

    private Service $acupuncture;

    private Booth $trainingA;

    private Booth $trainingB;

    private Booth $bed;

    private Staff $staffA;

    private Staff $staffB;

    private Staff $staffC;

    private Qualification $license;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.allow_admin_free_time', false);
        app(Settings::class)->set('reservation.slot_minutes', 5, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '21:00');

        $this->personal = Service::factory()->create(['name' => 'パーソナル60', 'duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $this->acupuncture = Service::factory()->create(['name' => 'はり30', 'duration_min' => 30, 'requires_staff' => true, 'is_active' => true]);
        $this->trainingA = Booth::factory()->create(['name' => 'トレーニングA', 'is_active' => true, 'sort_order' => 1]);
        $this->trainingB = Booth::factory()->create(['name' => 'トレーニングB', 'is_active' => true, 'sort_order' => 2]);
        $this->bed = Booth::factory()->create(['name' => 'ベッドA', 'is_active' => true, 'sort_order' => 3]);
        $this->personal->booths()->attach([$this->trainingA->id, $this->trainingB->id]);
        $this->acupuncture->booths()->attach([$this->bed->id]);

        $this->staffA = $this->staff('A');
        $this->staffB = $this->staff('B');
        // スタッフCは勤務中だがパーソナルもはりも施術可能スタッフではない。
        $this->staffC = $this->staff('C');
        $this->personal->staff()->attach([$this->staffA->user_id, $this->staffB->user_id]);
        $this->acupuncture->staff()->attach([$this->staffA->user_id, $this->staffB->user_id]);

        $this->license = Qualification::query()->where('code', 'acupuncturist')->firstOrFail();
        $this->acupuncture->qualifications()->attach($this->license->id);
        $this->staffA->qualifications()->attach($this->license->id);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** UT-B01〜B03：紐付けブースのどれか1つが空いていれば空きあり、全部埋まれば空きなし。 */
    public function test_ut_b01_to_b03_availability_uses_every_mapped_booth(): void
    {
        $this->assertContains('2026-10-01 14:00:00', $this->starts($this->personal, $this->staffB));

        $this->book($this->personal, $this->staffA, $this->trainingA);
        $this->assertContains('2026-10-01 14:00:00', $this->starts($this->personal, $this->staffB), 'トレーニングAだけ使用中なら、Bで取れる');

        $other = Service::factory()->create(['duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $other->staff()->attach($this->staffC->user_id);
        $this->book($other, $this->staffC, $this->trainingB);
        $this->assertNotContains('2026-10-01 14:00:00', $this->starts($this->personal, $this->staffB), 'A・B両方使用中なら空きなし');
        // 使用中の時間を抜けた15:00からは取れる。
        $this->assertContains('2026-10-01 15:00:00', $this->starts($this->personal, $this->staffB));
    }

    /** UT-B04：確定した具体ブースが予約とリソース枠の両方に保存される。 */
    public function test_ut_b04_concrete_booth_is_stored_on_reservation_and_resource_slots(): void
    {
        $reservation = $this->book($this->personal, $this->staffA, null, '14:00', 5);

        $this->assertSame($this->trainingA->id, $reservation->booth_id);
        $boothSlots = $reservation->resourceSlots()->where('resource_type', ResourceType::Booth->value)->get();
        // 14:00〜15:05（予約60分＋終了後5分）を5分刻みで13枠。
        $this->assertCount(13, $boothSlots);
        $this->assertSame([$this->trainingA->id], $boothSlots->pluck('resource_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $staffSlots = $reservation->resourceSlots()->where('resource_type', ResourceType::Staff->value)->get();
        $this->assertCount(13, $staffSlots);
        $this->assertSame([$this->staffA->user_id], $staffSlots->pluck('resource_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
    }

    /** UT-B05 / UT-B06：同じブースの同時間は不可、別ブースなら同時間でも可。 */
    public function test_ut_b05_b06_same_booth_is_exclusive_but_different_booths_share_the_time(): void
    {
        $this->book($this->personal, $this->staffA, $this->trainingA);

        $this->assertRejected(fn () => $this->book($this->personal, $this->staffB, $this->trainingA));
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffB, $this->trainingA, '14:30'));
        $this->assertSame($this->trainingB->id, $this->book($this->personal, $this->staffB, $this->trainingB)->booth_id);
        $this->assertSame(2, Reservation::query()->where('starts_at', '2026-10-01 14:00:00')->count());
    }

    /** UT-S01〜S03：施術可能スタッフだけが候補になり、空いていても施術できないスタッフは予約不可。 */
    public function test_ut_s01_to_s03_staff_capability_limits_candidates_and_booking(): void
    {
        $options = $this->serviceOption($this->personal);
        $this->assertSame([$this->staffA->user_id, $this->staffB->user_id], $options['staff_ids']);
        $this->assertSame([$this->trainingA->id, $this->trainingB->id], $options['booth_ids']);

        foreach (app(AvailabilityService::class)->openStartTimes($this->personal->id, null, null, CarbonImmutable::parse('2026-10-01')) as $slot) {
            $this->assertNotContains($this->staffC->user_id, $slot['available_staff_ids']);
        }
        $this->assertSame([], $this->starts($this->personal, $this->staffC));
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffC, null), 'staff_id');
    }

    /** UT-Q01〜Q03：資格あり＋はりは可、資格なし＋はりは不可、資格不要メニューは資格で弾かない。 */
    public function test_ut_q01_to_q03_qualification_rules(): void
    {
        $this->assertSame([$this->staffA->user_id], $this->serviceOption($this->acupuncture)['staff_ids']);
        $this->assertSame($this->bed->id, $this->book($this->acupuncture, $this->staffA, null)->booth_id);
        $this->assertRejected(fn () => $this->book($this->acupuncture, $this->staffB, null, '16:00'), 'staff_id');

        // パーソナルは必要資格が無いので、資格を持たないスタッフBでも予約できる。
        $this->assertSame(0, $this->staffB->qualifications()->count());
        $this->assertSame($this->trainingA->id, $this->book($this->personal, $this->staffB, null)->booth_id);
    }

    /** UT-Q04：必要資格が複数あるメニューは、全部を保有するスタッフだけが担当できる。 */
    public function test_ut_q04_multiple_required_qualifications_need_all_of_them(): void
    {
        $second = Qualification::query()->create(['code' => 'verify_second', 'name' => '検証用資格', 'is_active' => true, 'sort_order' => 99]);
        $this->acupuncture->qualifications()->attach($second->id);
        $this->staffB->qualifications()->attach([$this->license->id]);

        // AもBも1つしか持たない → 候補なし。
        $this->assertSame([], $this->serviceOption($this->acupuncture)['staff_ids']);
        $this->assertRejected(fn () => $this->book($this->acupuncture, $this->staffA, null), 'staff_id');

        $this->staffA->qualifications()->attach($second->id);
        $this->assertSame([$this->staffA->user_id], $this->serviceOption($this->acupuncture)['staff_ids']);
        $this->assertSame('14:00', $this->book($this->acupuncture, $this->staffA, null)->starts_at->format('H:i'));
        $this->assertRejected(fn () => $this->book($this->acupuncture, $this->staffB, null, '16:00'), 'staff_id');
    }

    /** UT-I01〜I03：インターバルは後ろに確保し、開始はずらさない。0分・15分の境界。 */
    public function test_ut_i01_to_i03_buffer_boundaries(): void
    {
        $five = $this->book($this->personal, $this->staffA, $this->trainingA, '14:00', 5);
        $this->assertSame(['14:00', '15:05', 60], [$five->starts_at->format('H:i'), $five->ends_at->format('H:i'), $five->bookedMinutes()]);
        $starts = $this->starts($this->personal, $this->staffA);
        $this->assertNotContains('2026-10-01 15:00:00', $starts);
        $this->assertContains('2026-10-01 15:05:00', $starts);

        $zero = $this->book($this->personal, $this->staffB, $this->trainingB, '11:00', 0);
        $this->assertSame('12:00', $zero->ends_at->format('H:i'));
        $this->assertContains('2026-10-01 12:00:00', $this->starts($this->personal, $this->staffB));
        $this->assertSame('12:00', $this->book($this->personal, $this->staffB, $this->trainingB, '12:00')->starts_at->format('H:i'));

        $fifteen = $this->book($this->personal, $this->staffB, $this->trainingB, '17:00', 15);
        $this->assertSame('18:15', $fifteen->ends_at->format('H:i'));
        $starts = $this->starts($this->personal, $this->staffB);
        $this->assertNotContains('2026-10-01 18:10:00', $starts);
        $this->assertContains('2026-10-01 18:15:00', $starts);
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffB, $this->trainingB, '18:10'));
    }

    /** UT-I05：空き枠計算と予約作成が、新しい予約のインターバル込みで同じ判定をする。 */
    public function test_ut_i05_availability_and_create_agree_on_the_new_reservations_own_buffer(): void
    {
        $this->book($this->personal, $this->staffA, $this->trainingA, '15:00');

        // 14:00開始・インターバル5分だと15:05まで占有し、15:00の予約と重なる。
        $withBuffer = array_column(app(AvailabilityService::class)->openStartTimes($this->personal->id, $this->staffA->user_id, null, CarbonImmutable::parse('2026-10-01'), 5), 'starts_at');
        $this->assertNotContains('2026-10-01 14:00:00', $withBuffer);
        $this->assertRejected(fn () => $this->book($this->personal, $this->staffA, null, '14:00', 5));
        $this->assertContains('2026-10-01 13:55:00', $withBuffer);
        // 13:55開始なら 13:55〜14:55＋インターバル5分＝15:00 でちょうど隣接して取れる。
        $this->assertSame('15:00', $this->book($this->personal, $this->staffA, null, '13:55', 5)->ends_at->format('H:i'));
    }

    /** UT-I04 / UT-I05 / 17：編集（PUT）・ドラッグ移動の両経路でインターバルと延長分を保って再計算する。 */
    public function test_ut_i04_update_and_drag_keep_buffer_extension_and_recompute_booth(): void
    {
        $manager = $this->roleUser('manager');
        $this->actingAs($manager)->post('/admin/reservations', [
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $this->personal->id,
            'staff_id' => $this->staffA->user_id,
            'starts_at' => '2026-10-01 14:00:00',
            'buffer_min' => 10,
        ])->assertSessionHasNoErrors();
        $reservation = Reservation::query()->latest('id')->firstOrFail();
        $this->assertSame(['15:10', $this->trainingA->id], [$reservation->ends_at->format('H:i'), $reservation->booth_id]);

        $reservation = app(ReservationService::class)->extend((int) $reservation->id, 30, null, (int) $reservation->version, null);
        $this->assertSame('15:40', $reservation->ends_at->format('H:i'));

        // 16:00のトレーニングAは別予約で使用中 → 編集で16:00へ動かすとBへ再確定する。
        $this->book($this->personal, $this->staffB, $this->trainingA, '16:00');
        $this->actingAs($manager)->put("/admin/reservations/{$reservation->id}", [
            'starts_at' => '2026-10-01 16:00:00',
            'staff_id' => $this->staffA->user_id,
            'version' => $reservation->version,
        ])->assertSessionHasNoErrors();
        $reservation->refresh();
        $this->assertSame(['16:00', '17:40', 90, 10, $this->trainingB->id], [
            $reservation->starts_at->format('H:i'), $reservation->ends_at->format('H:i'), $reservation->bookedMinutes(), (int) $reservation->buffer_min, $reservation->booth_id,
        ]);
        $this->assertSame(1, $reservation->segments()->count());

        $this->actingAs($manager)->put("/admin/schedule/reservations/{$reservation->id}/time", [
            'starts_at' => '2026-10-01 18:00:00',
            'version' => $reservation->version,
        ])->assertSessionHasNoErrors();
        $reservation->refresh();
        $this->assertSame(['18:00', '19:40', 90, 10], [$reservation->starts_at->format('H:i'), $reservation->ends_at->format('H:i'), $reservation->bookedMinutes(), (int) $reservation->buffer_min]);
        // 旧時間帯のリソース枠は解放され、新しい時間帯（100分＝20枠×スタッフ・ブース）だけが残る。
        $this->assertSame(40, $reservation->resourceSlots()->count());
        $this->assertSame('18:00', CarbonImmutable::parse((string) $reservation->resourceSlots()->min('slot_start'))->format('H:i'));
    }

    /** UT-RS04 / UT-RS05：予約60分に対し、実施55分は保存でき、延長なしの65分は保存できない。 */
    public function test_ut_rs04_rs05_composition_total_against_reserved_minutes(): void
    {
        $reservation = $this->book($this->personal, $this->staffA, $this->trainingA);
        $entries = app(CheckoutEntryService::class);
        $visit = $entries->openForReservation($reservation, null);

        $entries->saveVisit($visit, $this->visitPayload([[$this->personal, 55]]), null);
        $this->assertSame(55, (int) $visit->treatments()->sum('actual_minutes'));

        try {
            $entries->saveVisit($visit, $this->visitPayload([[$this->personal, 50], [$this->acupuncture, 15]]), null);
            $this->fail('延長なしで65分は保存できない');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('treatments', $exception->errors());
        }
        $this->assertSame(55, (int) $visit->treatments()->sum('actual_minutes'));
    }

    /** UT-E03 / UT-E04：スタッフは空いていてもブースが後ろで埋まっていれば延長不可、両方空けば可。 */
    public function test_ut_e03_e04_extension_checks_the_booth_as_well_as_the_staff(): void
    {
        $reservation = $this->book($this->personal, $this->staffA, $this->trainingA);
        $blocker = $this->book($this->personal, $this->staffB, $this->trainingA, '15:15');

        $this->assertRejected(fn () => app(ReservationService::class)->extend((int) $reservation->id, 30, null, (int) $reservation->version, null));
        $this->assertSame('15:00', $reservation->fresh()->ends_at->format('H:i'));

        app(ReservationService::class)->cancel($blocker, '検証', null);
        $extended = app(ReservationService::class)->extend((int) $reservation->id, 30, null, (int) $reservation->version, null);
        $this->assertSame('15:30', $extended->ends_at->format('H:i'));
    }

    /** IT-R01〜R06：管理画面の予約作成APIで、夫婦同時パーソナルと各失敗ケース。 */
    public function test_it_r01_to_r06_admin_http_booking_matrix(): void
    {
        $manager = $this->roleUser('manager');
        $post = fn (Service $service, Staff $staff, ?Booth $booth) => $this->actingAs($manager)->postJson('/admin/reservations', array_filter([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => $booth?->id,
            'starts_at' => '2026-10-01 14:00:00',
        ], fn ($value): bool => $value !== null));

        $post($this->personal, $this->staffA, $this->trainingA)->assertSessionHasNoErrors();   // IT-R01
        $post($this->personal, $this->staffB, $this->trainingB)->assertSessionHasNoErrors();   // IT-R02 夫婦同時
        $this->assertSame(2, Reservation::query()->where('starts_at', '2026-10-01 14:00:00')->count());

        // IT-R03：スタッフは施術可能で空きありだが、トレーニングBは使用中。
        $staffD = $this->staff('D');
        $this->personal->staff()->attach($staffD->user_id);
        // 明示したブースが他予約で使用中の場合は、DB一意制約で409（「指定の時間帯は既に予約されています」）。
        $post($this->personal, $staffD, $this->trainingB)->assertStatus(409)->assertJsonValidationErrors('reservation');
        $post($this->personal, $staffD, null)->assertUnprocessable()->assertJsonValidationErrors('booth_id');
        // IT-R04：ブースは空き（ベッドはパーソナル不可のため新たにトレーニングCを用意）、スタッフBは使用中。
        $trainingC = Booth::factory()->create(['name' => 'トレーニングC', 'is_active' => true, 'sort_order' => 4]);
        $this->personal->booths()->attach($trainingC->id);
        $post($this->personal, $this->staffB, $trainingC)->assertStatus(409)->assertJsonValidationErrors('reservation');
        // IT-R05：スタッフC・トレーニングCとも空きだが、スタッフCは施術可能スタッフでない。
        $post($this->personal, $this->staffC, $trainingC)->assertUnprocessable()->assertJsonValidationErrors('staff_id');
        // IT-R06：はり資格の無いスタッフでは、ベッドもスタッフも空いていても不可。
        $this->acupuncture->staff()->attach($staffD->user_id);
        $post($this->acupuncture, $staffD, $this->bed)->assertUnprocessable()->assertJsonValidationErrors('staff_id');

        $this->assertSame(2, Reservation::query()->count());
        $this->assertSame(0, Reservation::query()->where('staff_id', $staffD->user_id)->count());
    }

    /** 16：空き枠APIはメニュー・スタッフ・ブースの組合せすべてでメニュー×ブースの紐付けを使う。 */
    public function test_availability_api_combinations_use_the_menu_booth_mapping(): void
    {
        $manager = $this->roleUser('manager');
        $this->book($this->personal, $this->staffA, $this->trainingA);
        $get = fn (array $query) => collect($this->actingAs($manager)
            ->getJson('/admin/reservations/availability?'.http_build_query(['service_id' => $this->personal->id, 'date' => '2026-10-01', ...$query]))
            ->assertOk()->json())->keyBy('starts_at');

        $menuOnly = $get([]);
        $this->assertSame([$this->staffB->user_id], $menuOnly['2026-10-01 14:00:00']['available_staff_ids']);
        $this->assertTrue($get(['staff_id' => $this->staffB->user_id])->has('2026-10-01 14:00:00'));
        $this->assertFalse($get(['staff_id' => $this->staffA->user_id])->has('2026-10-01 14:00:00'));
        // 1つ目のブース（A）だけを見て空きなしにしない：B指定なら空き、A指定なら空きなし。
        $this->assertTrue($get(['booth_id' => $this->trainingB->id])->has('2026-10-01 14:00:00'));
        $this->assertFalse($get(['booth_id' => $this->trainingA->id])->has('2026-10-01 14:00:00'));
        $this->assertTrue($get(['staff_id' => $this->staffB->user_id, 'booth_id' => $this->trainingB->id])->has('2026-10-01 14:00:00'));
        $this->assertFalse($get(['staff_id' => $this->staffB->user_id, 'booth_id' => $this->trainingA->id])->has('2026-10-01 14:00:00'));
        // 紐付いていないベッドを指定しても空き枠は出ない。
        $this->assertFalse($get(['booth_id' => $this->bed->id])->has('2026-10-01 14:00:00'));
        $withBooths = $get(['with_booths' => 1]);
        $this->assertSame([$this->trainingB->id], $withBooths['2026-10-01 14:00:00']['available_booth_ids']);
    }

    /** @return list<string> */
    private function starts(Service $service, Staff $staff): array
    {
        return array_column(app(AvailabilityService::class)->openStartTimes($service->id, $staff->user_id, null, CarbonImmutable::parse('2026-10-01')), 'starts_at');
    }

    /** @return array<string, mixed> */
    private function serviceOption(Service $service): array
    {
        return collect(app(ReservationFormOptionsQuery::class)->get()['services'])->firstWhere('id', $service->id);
    }

    /** @param list<array{0: Service, 1: int}> $composition */
    private function visitPayload(array $composition): array
    {
        $cursor = CarbonImmutable::parse('2026-10-01 14:00');
        $treatments = [];
        foreach ($composition as [$service, $minutes]) {
            $treatments[] = ['service_id' => $service->id, 'actual_minutes' => $minutes, 'started_at' => $cursor->format('H:i'), 'booth_id' => $this->trainingA->id,
                'staff' => [['staff_id' => $this->staffA->user_id, 'actual_minutes' => $minutes]]];
            $cursor = $cursor->addMinutes($minutes);
        }

        return ['primary_staff_id' => $this->staffA->user_id, 'nominated_staff_ids' => [], 'treatments' => $treatments, 'lines' => [], 'tenders' => []];
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
        $staff = Staff::factory()->create(['display_name' => "検証担当{$name}", 'is_bookable' => true]);
        StaffShift::query()->create(['staff_id' => $staff->user_id, 'work_date' => '2026-10-01', 'start_at' => '10:00:00', 'end_at' => '21:00:00']);

        return $staff;
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function assertRejected(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('予約できてはならない'.($field ? "（{$field}）" : ''));
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            } else {
                $this->addToAssertionCount(1);
            }
        } catch (SlotUnavailableException) {
            $this->assertNull($field, 'SlotUnavailable ではなく項目エラーを期待');
        }
    }
}
