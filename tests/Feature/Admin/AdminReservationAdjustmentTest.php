<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminReservationAdjustmentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_customer_cannot_request_admin_adjustment(): void
    {
        [$reservation] = $this->paidReservation();
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        $response = $this->actingAs($customer->user)
            ->post(route('admin.reservations.adjustment', $reservation), ['final_amount' => 6000]);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(0, Payment::query()->where('kind', PaymentKind::SingleAddon->value)->count());
    }

    public function test_manager_requires_password_confirmation(): void
    {
        [$reservation] = $this->paidReservation();
        $manager = $this->staffUser('manager');

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => null])
            ->post(route('admin.reservations.adjustment', $reservation), ['final_amount' => 6000])
            ->assertRedirect(route('password.confirm'));
    }

    public function test_refund_branch_requires_refund_execute_permission(): void
    {
        [$reservation] = $this->paidReservation();
        $role = Role::query()->create(['name' => 'adjuster', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', [
            'admin.access',
            'reservations.manage',
        ])->get());
        $user = User::factory()->create();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user->assignRole($role);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.reservations.adjustment', $reservation), ['final_amount' => 4000])
            ->assertForbidden();

        $this->assertNull($reservation->refresh()->final_amount);

        $manager = $this->staffUser('manager');
        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.reservations.adjustment', $reservation), ['final_amount' => 4000])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(1000, (int) $reservation->payments()->firstOrFail()->refunded_amount);
    }

    public function test_manager_can_issue_addon_and_operation_is_audited(): void
    {
        [$reservation] = $this->paidReservation();
        $manager = $this->staffUser('manager');

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.reservations.adjustment', $reservation), ['final_amount' => 6500])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::SingleAddon->value,
            'amount' => 1500,
            'status' => PaymentStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.addon_payment_requested',
            'actor_user_id' => $manager->id,
        ]);
    }

    public function test_edit_props_include_reservation_payment_rollup(): void
    {
        [$reservation] = $this->paidReservation();
        $reservation->forceFill(['final_amount' => 6500])->save();
        $manager = $this->staffUser('manager');

        $this->actingAs($manager)
            ->get(route('admin.reservations.edit', $reservation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reservations/Edit')
                ->where('payment_summary.net_received', 5000)
                ->where('payment_summary.delta', 1500)
                ->has('payment_summary.payments', 1)
                ->where('payment_summary.payments.0.kind_label', '予約決済'));
    }

    /** @return array{0: Reservation, 1: Payment} */
    private function paidReservation(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['price' => 5000]);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'status' => 'confirmed',
            'payment_method' => 'single',
            'payment_status' => 'paid',
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Single,
            'amount' => 5000,
            'status' => PaymentStatus::Succeeded,
            'stripe_payment_intent_id' => 'pi_admin_adjustment',
            'stripe_charge_id' => 'ch_admin_adjustment',
            'paid_at' => now(),
        ]);

        return [$reservation, $payment];
    }

    private function staffUser(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }
}
