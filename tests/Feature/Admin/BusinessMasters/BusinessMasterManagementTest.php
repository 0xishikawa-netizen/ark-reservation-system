<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\BusinessMasters;

use App\Domain\Business\SalesTargetService;
use App\Domain\Business\StaffEmploymentService;
use App\Domain\Business\StoreCalendarService;
use App\Domain\Business\TaxRateService;
use App\Models\EmploymentType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\ServiceAnalysisCategory;
use App\Models\Staff;
use App\Models\TaxCategory;
use App\Models\User;
use App\Support\Business\BusinessTime;
use Database\Seeders\Phase11MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessMasterManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_seed_is_idempotent_and_does_not_guess_tax_rates(): void
    {
        $this->seed(Phase11MasterSeeder::class);
        $this->seed(Phase11MasterSeeder::class);

        $this->assertDatabaseCount('service_analysis_categories', 5);
        $this->assertDatabaseCount('payment_methods', 9);
        $this->assertDatabaseCount('employment_types', 2);
        $this->assertDatabaseCount('tax_rates', 0);
    }

    public function test_existing_service_remains_valid_and_can_receive_nullable_master_links(): void
    {
        $service = Service::query()->create(['name' => '既存メニュー', 'duration_min' => 60, 'price' => 8000]);
        $category = ServiceAnalysisCategory::query()->create(['code' => 'M', 'name' => 'マッサージ']);
        $tax = TaxCategory::query()->create(['code' => 'standard', 'name' => '標準税率']);

        $this->assertNull($service->analysis_category_id);
        $service->update(['analysis_category_id' => $category->id, 'tax_category_id' => $tax->id]);
        $this->assertSame('マッサージ', $service->fresh()->analysisCategory?->name);
    }

    public function test_inactive_analysis_category_cannot_be_assigned_to_new_service(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $inactive = ServiceAnalysisCategory::query()->create([
            'code' => 'OLD', 'name' => '旧分類', 'is_active' => false,
        ]);

        $this->actingAs($admin)->postJson('/admin/services', [
            'name' => '無効分類メニュー', 'duration_min' => 60, 'price' => 5000,
            'analysis_category_id' => $inactive->id, 'requires_staff' => false, 'sort_order' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('analysis_category_id');
    }

    public function test_tax_rate_uses_half_open_periods_and_rejects_overlap(): void
    {
        $category = TaxCategory::query()->create(['code' => 'standard', 'name' => '標準税率']);
        $rates = app(TaxRateService::class);
        $this->assertNull($rates->forDate($category, '2024-12-31'));
        $rates->save(['tax_category_id' => $category->id, 'rate_bps' => 800, 'effective_from' => '2025-01-01', 'effective_to' => '2026-01-01'], null, null);
        $rates->save(['tax_category_id' => $category->id, 'rate_bps' => 1000, 'effective_from' => '2026-01-01', 'effective_to' => null], null, null);

        $this->assertSame(800, $rates->forDate($category, '2025-12-31')?->rate_bps);
        $this->assertSame(1000, $rates->forDate($category, '2026-01-01')?->rate_bps);

        $this->expectException(ValidationException::class);
        $rates->save(['tax_category_id' => $category->id, 'rate_bps' => 900, 'effective_from' => '2025-12-01', 'effective_to' => '2026-02-01'], null, null);
    }

    public function test_payment_method_can_be_reordered_and_disabled_without_changing_stripe_payment(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $payment = Payment::factory()->create(['provider' => 'stripe']);
        $method = PaymentMethod::query()->create([
            'code' => 'stripe', 'name' => 'Stripe', 'is_enabled' => true,
            'display_order' => 80, 'external_provider' => 'stripe',
        ]);

        $this->actingAs($admin)->put("/admin/settings/business-masters/payment-methods/{$method->id}", [
            'code' => 'stripe', 'name' => 'Stripe', 'is_enabled' => false,
            'display_order' => 5, 'external_provider' => 'stripe',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payment_methods', ['id' => $method->id, 'is_enabled' => false, 'display_order' => 5]);
        $this->assertSame('stripe', $payment->fresh()->provider);
    }

    public function test_calendar_supports_closed_special_and_normal_days_in_business_timezone(): void
    {
        $calendar = app(StoreCalendarService::class);
        $calendar->save(['business_date' => '2026-12-31', 'status' => 'closed'], null);
        $calendar->save(['business_date' => '2027-01-02', 'status' => 'special_hours', 'opens_at' => '12:00', 'closes_at' => '18:00'], null);

        $this->assertTrue($calendar->isClosed('2026-12-31'));
        $this->assertSame('12:00', $calendar->resolve('2027-01-02')['opens_at']);
        $this->assertSame('normal', $calendar->resolve('2027-01-03')['status']);
        $this->assertSame('2027-01-01', app(BusinessTime::class)->businessDate('2026-12-31T15:30:00Z')->toDateString());
        $this->assertSame('Asia/Tokyo', app(BusinessTime::class)->timezone());
    }

    public function test_sales_target_month_override_falls_back_to_default_across_year_boundary(): void
    {
        $targets = app(SalesTargetService::class);
        $targets->setDefaultAmount(1_000_000, null);
        $targets->setMonthly('2026-12', 1_200_000, null);

        $this->assertSame(1_200_000, $targets->forMonth('2026-12-31'));
        $this->assertSame(1_000_000, $targets->forMonth('2027-01-01'));
        $targets->setMonthly('2026-12', 1_300_000, null);
        $this->assertDatabaseCount('monthly_sales_targets', 1);
        $this->assertSame(1_300_000, $targets->forMonth('2026-12-01'));
    }

    public function test_staff_employment_history_preserves_effective_periods(): void
    {
        $staff = Staff::factory()->create();
        $employee = EmploymentType::query()->create(['code' => 'employee', 'name' => '社員']);
        $partTime = EmploymentType::query()->create(['code' => 'part_time', 'name' => 'アルバイト']);
        $employment = app(StaffEmploymentService::class);
        $employment->assignFrom($staff, $partTime, '2026-01-01', null);
        $employment->assignFrom($staff, $employee, '2026-04-01', null);

        $this->assertDatabaseHas('staff_employment_periods', ['staff_id' => $staff->user_id, 'employment_type_id' => $partTime->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-04-01']);
        $this->assertDatabaseHas('staff_employment_periods', ['staff_id' => $staff->user_id, 'employment_type_id' => $employee->id, 'effective_from' => '2026-04-01', 'effective_to' => null]);
    }

    public function test_business_master_routes_are_permission_gated_and_audited(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');
        $this->actingAs($staff)->get('/admin/settings/business-masters')->assertForbidden();

        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->post('/admin/settings/business-masters/analysis-categories', [
            'code' => 'M', 'name' => 'マッサージ', 'is_active' => true, 'sort_order' => 10,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_analysis_category.created', 'actor_user_id' => $admin->id]);
    }
}
