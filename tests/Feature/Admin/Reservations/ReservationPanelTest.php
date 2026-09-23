<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketProduct;
use App\Models\TicketWallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReservationPanelTest extends TestCase
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

    public function test_panel_returns_customer_reservation_history_and_assets(): void
    {
        $service = Service::factory()->create(['name' => 'パーソナル60分', 'duration_min' => 60, 'price' => 8000]);
        $staff = Staff::factory()->create(['display_name' => '山田']);
        $customer = Customer::factory()->create([
            'gender' => 'female',
            'kana' => 'ヤマモト ハナ',
            'note' => "アレルギーあり\n強めの施術希望",
        ]);
        $customer->user->update(['name' => '山本 花']);

        // 過去（来店完了）2件 + 今回（確定）1件 + 未来（確定）1件
        $this->reservation($customer, $service, $staff, '2026-08-01 10:00:00', ReservationStatus::Completed);
        $this->reservation($customer, $service, $staff, '2026-08-18 14:00:00', ReservationStatus::Completed);
        $current = $this->reservation($customer, $service, $staff, '2026-09-14 11:00:00', ReservationStatus::Confirmed);
        $this->reservation($customer, $service, $staff, '2026-09-20 10:00:00', ReservationStatus::Confirmed);

        $product = TicketProduct::factory()->create(['name' => 'パーソナル10回券']);
        TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'balance' => 4,
            'purchased_count' => 10,
            'expires_at' => '2026-12-31',
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson("/admin/reservations/{$current->id}/panel")
            ->assertOk()
            ->json();

        $this->assertTrue($payload['can']['manage']);
        $this->assertTrue($payload['can']['view_customer']);

        // 顧客
        $this->assertSame('山本 花', $payload['customer']['name']);
        $this->assertSame('female', $payload['customer']['gender']);
        $this->assertSame(2, $payload['customer']['visit_count']);
        $this->assertSame('2026-08-01', $payload['customer']['first_visit_at']);
        $this->assertSame('2026-08-18', $payload['customer']['last_visit_at']);
        $this->assertStringContainsString('アレルギー', $payload['customer']['note']);

        // 今回の予約
        $this->assertSame('予約確定', $payload['reservation']['status_label']);
        $this->assertSame($service->id, $payload['reservation']['service_id']);
        $this->assertSame('パーソナル60分', $payload['reservation']['service_name']);
        $this->assertSame($staff->user_id, $payload['reservation']['staff_id']);
        $this->assertSame('山田', $payload['reservation']['staff_name']);
        $this->assertTrue($payload['reservation']['can_complete']);
        $this->assertTrue($payload['reservation']['can_cancel']);
        $this->assertTrue($payload['reservation']['can_no_show']);

        // 今後の予約（今回の予約自身も未来なので含まれる）／来店履歴（新しい順・過去のみ）
        $this->assertCount(2, $payload['upcoming']);
        $this->assertContains(
            '2026-09-20',
            array_map(static fn (array $r): string => substr($r['starts_at'], 0, 10), $payload['upcoming']),
        );
        $this->assertCount(2, $payload['history']['items']);
        $this->assertSame('2026-08-18', $payload['history']['items'][0]['date']);
        $this->assertSame('来店完了', $payload['history']['items'][0]['status_label']);
        $this->assertSame(2, $payload['history']['total']);
        $this->assertFalse($payload['history']['has_more']);

        // 回数券（有効なウォレットが要約付きで出る）
        $this->assertCount(1, $payload['tickets']);
        $this->assertSame('パーソナル10回券', $payload['tickets'][0]['product_name']);
        $this->assertArrayHasKey('available', $payload['tickets'][0]);
        $this->assertArrayHasKey('expires_at', $payload['tickets'][0]);
    }

    public function test_history_is_capped_and_flags_more(): void
    {
        $service = Service::factory()->create(['duration_min' => 30]);
        $staff = Staff::factory()->create();
        $customer = Customer::factory()->create();

        for ($i = 1; $i <= 26; $i++) {
            $this->reservation(
                $customer,
                $service,
                $staff,
                CarbonImmutable::parse('2026-08-01 10:00:00')->subDays($i)->format('Y-m-d H:i:s'),
                ReservationStatus::Completed,
            );
        }
        $current = $this->reservation($customer, $service, $staff, '2026-09-14 11:00:00', ReservationStatus::Confirmed);

        $payload = $this->actingAs($this->admin())
            ->getJson("/admin/reservations/{$current->id}/panel")
            ->assertOk()
            ->json();

        $this->assertCount(20, $payload['history']['items']);
        $this->assertSame(26, $payload['history']['total']);
        $this->assertTrue($payload['history']['has_more']);
    }

    public function test_terminal_reservation_offers_no_state_actions(): void
    {
        $current = $this->reservation(
            Customer::factory()->create(),
            Service::factory()->create(['duration_min' => 30]),
            Staff::factory()->create(),
            '2026-08-01 10:00:00',
            ReservationStatus::Completed,
        );

        $payload = $this->actingAs($this->admin())
            ->getJson("/admin/reservations/{$current->id}/panel")
            ->assertOk()
            ->json();

        $this->assertFalse($payload['reservation']['can_complete']);
        $this->assertFalse($payload['reservation']['can_cancel']);
        $this->assertFalse($payload['reservation']['can_no_show']);
        $this->assertSame('来店完了', $payload['reservation']['status_label']);
    }

    public function test_viewer_without_customers_view_gets_reservation_only(): void
    {
        $current = $this->reservation(
            Customer::factory()->create(['note' => '見えてはいけないメモ']),
            Service::factory()->create(['duration_min' => 30]),
            Staff::factory()->create(),
            '2026-09-14 11:00:00',
            ReservationStatus::Confirmed,
        );

        // admin.access + reservations.view は持つが customers.view / reservations.manage は持たない疑似アカウント。
        $limited = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $limited->givePermissionTo('admin.access', 'reservations.view');

        $payload = $this->actingAs($limited)
            ->getJson("/admin/reservations/{$current->id}/panel")
            ->assertOk()
            ->json();

        $this->assertFalse($payload['can']['manage']);
        $this->assertFalse($payload['can']['view_customer']);
        $this->assertNull($payload['customer']);
        $this->assertSame([], $payload['tickets']);
        $this->assertNull($payload['membership']);
        $this->assertSame([], $payload['history']['items']);
        $this->assertSame([], $payload['upcoming']);
        $this->assertFalse($payload['reservation']['can_cancel']);
        // 予約そのものの情報（PII 以外）は閲覧者にも返る。
        $this->assertNotEmpty($payload['reservation']['service_name']);
        $this->assertArrayHasKey('status_label', $payload['reservation']);
    }

    public function test_panel_requires_reservations_view(): void
    {
        $current = $this->reservation(
            Customer::factory()->create(),
            Service::factory()->create(['duration_min' => 30]),
            Staff::factory()->create(),
            '2026-09-14 11:00:00',
            ReservationStatus::Confirmed,
        );

        $stranger = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($stranger)
            ->getJson("/admin/reservations/{$current->id}/panel")
            ->assertForbidden();
    }

    public function test_schedule_board_carries_focus_reservation_id_from_the_query_string(): void
    {
        $current = $this->reservation(
            Customer::factory()->create(),
            Service::factory()->create(['duration_min' => 30]),
            Staff::factory()->create(),
            '2026-09-14 11:00:00',
            ReservationStatus::Confirmed,
        );
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->assignRole('staff');

        $this->actingAs($viewer)
            ->get("/admin/schedule?date=2026-09-14&reservation={$current->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Schedule/Index')
                ->where('focus_reservation_id', $current->id));

        $this->actingAs($viewer)
            ->getJson('/admin/schedule?reservation=99999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
    }

    private function reservation(
        Customer $customer,
        Service $service,
        Staff $staff,
        string $startsAt,
        ReservationStatus $status,
    ): Reservation {
        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes((int) $service->duration_min),
            'status' => $status,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
