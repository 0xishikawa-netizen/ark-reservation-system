<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Domain\Reporting\CustomerAnalyticsService;
use App\Domain\Visit\VisitCompletionService;
use App\Enums\Visit\VisitStatus;
use App\Models\AcquisitionChannel;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPurpose;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerKarteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 03:00:00', 'UTC'));
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_masters_are_seeded_from_sample_values(): void
    {
        $this->assertSame(
            ['ホットペッパー', 'EPARK', '紹介', 'チラシ', 'HP', 'OZmall', '都立', '看板', 'その他'],
            AcquisitionChannel::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(
            ['痛みを取りたい', '根本的に治したい', 'リラクゼーション', '運動不足解消', 'その他'],
            VisitPurpose::query()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    public function test_karte_is_saved_audited_and_snapshotted_at_first_visit_for_analytics(): void
    {
        $admin = $this->admin();
        $referrer = Customer::factory()->create();
        $customer = Customer::factory()->create();
        $hotpepper = AcquisitionChannel::query()->where('code', 'hotpepper')->sole();
        $purposes = VisitPurpose::query()->whereIn('code', ['pain_relief', 'root_cause'])->pluck('id')->all();

        $this->actingAs($admin)->put(route('admin.customers.karte.update', $customer), [
            'acquisition_channel_id' => $hotpepper->id,
            'visit_purpose_ids' => $purposes,
            'referrer_customer_id' => $referrer->user_id,
            'prefecture' => '東京都',
            'city' => '目黒区',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.customers.karte.update', $customer), ['city' => '自由が丘1-2-3'])
            ->assertSessionHasErrors('city');
        $this->actingAs($admin)->put(route('admin.customers.karte.update', $customer), ['prefecture' => '東京'])
            ->assertSessionHasErrors('prefecture');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.karte_updated', 'entity_id' => (string) $customer->user_id]);
        $this->assertStringNotContainsString((string) $referrer->user_id, (string) AuditLog::query()->where('action', 'customer.karte_updated')->value('summary'));

        $staff = Staff::factory()->create(['display_name' => '担当A']);
        $first = $this->completeWalkIn($customer, $staff, '2026-09-10');
        $this->assertNotNull($first->first_visit_karte_snapshot_at);
        $this->assertSame($hotpepper->id, (int) $first->first_visit_acquisition_channel_id);
        $this->assertTrue($first->first_visit_referred);
        $this->assertSame('目黒区', $first->first_visit_city);
        $this->assertSame(2, DB::table('visit_first_purposes')->where('visit_id', $first->id)->count());
        $this->completeWalkIn($customer, $staff, '2026-09-15');

        // 顧客情報を後から変えても初診snapshotと統計は変わらない。
        $this->actingAs($admin)->put(route('admin.customers.karte.update', $customer), ['prefecture' => '神奈川県', 'city' => '川崎市'])->assertSessionHasNoErrors();
        $other = Customer::factory()->create();
        $this->completeWalkIn($other, $staff, '2026-09-12');

        $summary = app(CustomerAnalyticsService::class)->forMonth(2026, 9, '2026-09-20');
        $this->assertSame(2, $summary->newCustomers);
        $motivation = collect($summary->breakdowns['motivation']['buckets'])->keyBy('label');
        $this->assertSame(1, $motivation['ホットペッパー']['count']);
        $this->assertSame(1, collect($summary->breakdowns['motivation']['buckets'])->firstWhere('value', null)['count']);
        $this->assertSame(1, collect($summary->breakdowns['municipality']['buckets'])->firstWhere('value', '東京都 目黒区')['count']);
        $this->assertSame(1, collect($summary->breakdowns['referrer']['buckets'])->firstWhere('value', 'true')['count']);
        $this->assertSame(1, collect($summary->breakdowns['visit_purpose']['buckets'])->firstWhere('label', '痛みを取りたい')['count']);

        $byChannel = collect($summary->crossTabs['motivation'])->keyBy('label');
        $this->assertSame(['new_customers' => 1, 'reached_2' => 1], ['new_customers' => $byChannel['ホットペッパー']['new_customers'], 'reached_2' => $byChannel['ホットペッパー']['reached_2']]);
        $this->assertSame(1.0, $byChannel['ホットペッパー']['reached_2_rate']);
        $staffRow = collect($summary->crossTabs['first_staff'])->firstWhere('label', '担当A');
        $this->assertSame(2, $staffRow['new_customers']);
        $this->assertSame(1, $staffRow['reached_2']);
        $this->assertSame(0.5, $staffRow['reached_2_rate']);
    }

    public function test_viewer_without_customer_manage_cannot_update_karte(): void
    {
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->givePermissionTo(['admin.access', 'customers.view']);
        $customer = Customer::factory()->create();

        $this->actingAs($viewer)->put(route('admin.customers.karte.update', $customer), ['prefecture' => '東京都'])->assertForbidden();
        $this->assertNull($customer->fresh()->prefecture);
    }

    private function completeWalkIn(Customer $customer, Staff $staff, string $date): Visit
    {
        $visit = Visit::factory()->create(['customer_id' => $customer->user_id, 'reservation_id' => null, 'status' => VisitStatus::Draft, 'business_date' => $date, 'primary_staff_id' => $staff->user_id, 'primary_staff_name_snapshot' => $staff->display_name]);
        $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id, 'status' => 'draft', 'actual_minutes' => 60]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'staff_id' => $staff->user_id, 'actual_minutes' => 60]);

        return app(VisitCompletionService::class)->completeWalkIn($visit)->visit;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
