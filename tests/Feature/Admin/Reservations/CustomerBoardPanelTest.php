<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBoardPanelTest extends TestCase
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

    public function test_board_panel_returns_customer_fields_without_pii_and_marks_todays_reservation(): void
    {
        $admin = $this->admin();
        $service = Service::factory()->create(['duration_min' => 30]);
        $customer = Customer::factory()->create([
            'gender' => 'male',
            'phone' => '090-1111-2222',
        ]);
        $customer->user->update(['name' => '検索対象 花子']);

        $todaysReservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'starts_at' => '2026-09-14 13:00:00',
            'ends_at' => '2026-09-14 13:30:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $payload = $this->actingAs($admin)
            ->getJson("/admin/customers/{$customer->user_id}/board-panel?date=2026-09-14")
            ->assertOk()
            ->json();

        $this->assertNull($payload['reservation']);
        $this->assertSame($todaysReservation->id, $payload['today_reservation_id']);
        $this->assertSame('検索対象 花子', $payload['customer']['name']);
        $this->assertSame($customer->member_no, $payload['customer']['member_no']);
        // 台帳での本人確認によく使うため、電話番号は表示権限（customers.view）と同じ条件で返す。
        $this->assertSame('090-1111-2222', $payload['customer']['phone']);
        $this->assertArrayNotHasKey('email', $payload['customer']);
        $this->assertArrayNotHasKey('birthday', $payload['customer']);
    }

    public function test_board_panel_without_reference_date_has_no_today_reservation_id(): void
    {
        $customer = Customer::factory()->create();

        $payload = $this->actingAs($this->admin())
            ->getJson("/admin/customers/{$customer->user_id}/board-panel")
            ->assertOk()
            ->json();

        $this->assertNull($payload['today_reservation_id']);
    }

    public function test_board_panel_hides_customer_details_without_customers_view_permission(): void
    {
        $customer = Customer::factory()->create(['note' => '見えないメモ']);
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->givePermissionTo('admin.access', 'reservations.view');

        $payload = $this->actingAs($viewer)
            ->getJson("/admin/customers/{$customer->user_id}/board-panel")
            ->assertOk()
            ->json();

        $this->assertFalse($payload['can']['view_customer']);
        $this->assertNull($payload['customer']);
    }

    public function test_board_panel_requires_reservations_view_permission(): void
    {
        $customer = Customer::factory()->create();
        $stranger = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($stranger)
            ->getJson("/admin/customers/{$customer->user_id}/board-panel")
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
