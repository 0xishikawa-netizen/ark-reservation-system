<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Ticket\UpdateTicketPolicy;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Ticket\TicketNoShowPolicy;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\User;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);

        $this->seed([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_customer_staff_and_manager_cannot_view_ticket_policy_settings(): void
    {
        foreach (['customer', 'staff', 'manager'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)
                ->get('/admin/settings/tickets')
                ->assertForbidden();
        }
    }

    public function test_admin_can_view_ticket_policy_settings(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Tickets')
                ->where('policy.no_show_policy', 'restore')
                ->where('policy.expiration_hold_policy', 'preserve_hold')
                ->where('options.no_show.0.value', 'restore')
                ->where('options.no_show.1.value', 'consume')
                ->where('options.expiration_hold.0.value', 'preserve_hold')
                ->where('auth.can.ticketPolicyManage', true));
    }

    public function test_update_requires_recent_password_confirmation(): void
    {
        $response = $this->actingAs($this->admin())
            ->patch('/admin/settings/tickets', $this->validPayload());

        $this->assertContains($response->getStatusCode(), [302, 423]);
        $this->assertDatabaseHas('settings', [
            'key' => 'ticket.no_show_policy',
            'value' => 'restore',
        ]);
    }

    public function test_admin_can_update_changed_policy_and_create_only_its_audit_log(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/tickets', $this->validPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '回数券運用設定を更新しました。')
            ->assertRedirect();

        $this->assertDatabaseHas('settings', [
            'key' => 'ticket.no_show_policy',
            'value' => 'consume',
            'type' => 'string',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'ticket.expiration_hold_policy',
            'value' => 'preserve_hold',
            'type' => 'string',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'ticket.policy.no_show.changed',
            'entity_type' => null,
            'entity_id' => null,
            'summary' => 'ticket.no_show_policy: restore → consume（理由: 運用変更）',
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'ticket.policy.expiration_hold.changed',
        ]);
    }

    public function test_reason_is_required_and_setting_is_not_updated(): void
    {
        $payload = $this->validPayload();
        unset($payload['reason']);

        $this->actingAs($this->admin())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patchJson('/admin/settings/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertNoShowPolicyIsRestore();
    }

    public function test_unknown_no_show_policy_is_rejected_and_not_saved(): void
    {
        $this->actingAs($this->admin())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patchJson('/admin/settings/tickets', [
                ...$this->validPayload(),
                'no_show_policy' => 'forfeit',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('no_show_policy');

        $this->assertNoShowPolicyIsRestore();
    }

    public function test_unknown_expiration_hold_policy_is_rejected_and_not_saved(): void
    {
        $this->actingAs($this->admin())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patchJson('/admin/settings/tickets', [
                ...$this->validPayload(),
                'expiration_hold_policy' => 'release_all',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expiration_hold_policy');

        $this->assertNoShowPolicyIsRestore();
        $this->assertDatabaseHas('settings', [
            'key' => 'ticket.expiration_hold_policy',
            'value' => 'preserve_hold',
        ]);
    }

    public function test_action_revalidates_policy_values_when_called_directly(): void
    {
        try {
            app(UpdateTicketPolicy::class)->execute(
                'forfeit',
                'preserve_hold',
                '直接呼び出し',
                $this->admin(),
            );
            $this->fail('不正なポリシー値が Action から保存されました。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('no_show_policy', $exception->errors());
        }

        $this->assertNoShowPolicyIsRestore();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_policy_change_does_not_apply_to_an_existing_ticket_hold(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'restore', 'string');
        [$customer, $service, $staff] = $this->bookableMasters();
        $product = TicketProduct::factory()->create([
            'total_count' => 3,
            'validity_days' => 90,
        ]);
        app(TicketLedgerService::class)->grant(
            customer: $customer,
            product: $product,
            count: 3,
            operationKey: 'policy-settings-non-retroactive',
            reason: '非遡及テスト用付与',
        );

        $reservation = app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: PaymentMethod::Ticket,
        ));

        $this->actingAs($this->admin())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/tickets', $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'key' => 'ticket.no_show_policy',
            'value' => 'consume',
        ]);
        $this->assertDatabaseHas('ticket_reservation_usages', [
            'reservation_id' => $reservation->id,
            'no_show_policy' => 'restore',
        ]);
        $this->assertSame(
            TicketNoShowPolicy::Restore,
            TicketReservationUsage::query()
                ->where('reservation_id', $reservation->id)
                ->value('no_show_policy'),
        );
    }

    /** @return array{no_show_policy: string, expiration_hold_policy: string, reason: string} */
    private function validPayload(): array
    {
        return [
            'no_show_policy' => 'consume',
            'expiration_hold_policy' => 'preserve_hold',
            'reason' => '運用変更',
        ];
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function assertNoShowPolicyIsRestore(): void
    {
        $this->assertDatabaseHas('settings', [
            'key' => 'ticket.no_show_policy',
            'value' => 'restore',
        ]);
    }

    /** @return array{Customer, Service, Staff} */
    private function bookableMasters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);

        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }
}
