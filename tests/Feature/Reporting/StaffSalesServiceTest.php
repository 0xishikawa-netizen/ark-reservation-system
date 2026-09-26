<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Accounting\CheckoutService;
use App\Domain\Reporting\StaffSalesService;
use App\Enums\Visit\VisitStatus;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitStaffNomination;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffSalesServiceTest extends TestCase
{
    use RefreshDatabase;

    private Staff $a;

    private Staff $b;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 03:00:00', 'UTC'));
        $this->a = Staff::factory()->create(['display_name' => 'A']);
        $this->b = Staff::factory()->create(['display_name' => 'B']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_nominated_sales_follow_per_staff_nomination_and_allocated_amounts(): void
    {
        // 両者指名: 10,000 = A6,000 + B4,000 → A指名6,000・B指名4,000（二重計上しない）。
        $this->visitSale('2026-09-10', [$this->a, $this->b], [[$this->a, 6000], [$this->b, 4000]]);
        // A だけ指名。主担当・担当でもBは非指名。
        $this->visitSale('2026-09-11', [$this->a], [[$this->a, 5000], [$this->b, 5000]]);
        // 指名未記録の旧来店は指名不明。
        $this->visitSale('2026-09-12', null, [[$this->b, 3000]]);
        // 来店なし会計の配分は非指名。
        $this->storeSale('2026-09-12', [[$this->a, 1100]]);

        $report = app(StaffSalesService::class)->forMonth(2026, 9);
        $rows = collect($report['staff_rows'])->keyBy('staff_name');
        $this->assertSame(['total' => 12100, 'nominated' => 11000, 'non' => 1100, 'unknown' => 0], $this->amounts($rows['A']));
        $this->assertSame(['total' => 12000, 'nominated' => 4000, 'non' => 5000, 'unknown' => 3000], $this->amounts($rows['B']));
        $this->assertNull($rows['B']['nominated_share']);
        $this->assertEqualsWithDelta(11000 / 12100, $rows['A']['nominated_share'], 1e-9);
        $this->assertSame(24100, $report['totals']['total_amount']);
        $this->assertSame(
            $report['totals']['total_amount'],
            $report['totals']['nominated_amount'] + $report['totals']['non_nominated_amount'] + $report['totals']['nomination_unknown_amount'],
        );

        $treatment = app(StaffSalesService::class)->forMonth(2026, 9, 'treatment_date');
        $this->assertSame(23000, $treatment['totals']['total_amount']);
    }

    public function test_page_requires_sales_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->givePermissionTo(['admin.access', 'reports.view']);
        $this->actingAs($viewer)->get(route('admin.reports.staff-sales'))->assertForbidden();

        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('admin.reports.staff-sales.data', ['year' => 2026, 'month' => 9]))->assertOk()
            ->assertJsonPath('data.month_key', '2026-09');
    }

    /** @return array{total:int,nominated:int,non:int,unknown:int} */
    private function amounts(array $row): array
    {
        return ['total' => $row['total_amount'], 'nominated' => $row['nominated_amount'], 'non' => $row['non_nominated_amount'], 'unknown' => $row['nomination_unknown_amount']];
    }

    /** @param list<Staff>|null $nominated @param list<array{0:Staff,1:int}> $allocations */
    private function visitSale(string $date, ?array $nominated, array $allocations): void
    {
        $visit = Visit::factory()->create(['business_date' => $date, 'status' => VisitStatus::Draft, 'reservation_id' => null,
            'nominations_recorded_at' => $nominated === null ? null : now()]);
        foreach ($nominated ?? [] as $staff) {
            VisitStaffNomination::query()->create(['visit_id' => $visit->id, 'staff_id' => $staff->user_id, 'staff_name_snapshot' => $staff->display_name]);
        }
        $visit->forceFill(['status' => VisitStatus::Completed, 'visit_sequence' => 1])->save();
        $this->finalize(app(CheckoutService::class)->createDraft($visit), $date, $allocations);
    }

    /** @param list<array{0:Staff,1:int}> $allocations */
    private function storeSale(string $date, array $allocations): void
    {
        $this->finalize(app(CheckoutService::class)->createStoreDraft(Customer::factory()->create(), $date), $date, $allocations);
    }

    /** @param list<array{0:Staff,1:int}> $allocations */
    private function finalize($checkout, string $date, array $allocations): void
    {
        $service = app(CheckoutService::class);
        $gross = array_sum(array_column($allocations, 1));
        $line = $service->addLine($checkout, ['item_type' => 'service', 'item_name_snapshot' => '施術', 'quantity' => 1, 'unit_amount' => $gross,
            'tax_rate_bps' => 1000, 'net_amount' => $gross - intdiv($gross, 11), 'tax_amount' => intdiv($gross, 11), 'gross_amount' => $gross]);
        foreach ($allocations as [$staff, $amount]) {
            $service->allocateStaff($line, $staff, $amount);
        }
        $service->addTender($checkout, PaymentMethod::factory()->create(), $gross, ['received_at' => CarbonImmutable::parse($date.' 12:00', 'Asia/Tokyo')->utc()]);
        $service->syncTotals($checkout);
        $service->finalize($checkout);
    }
}
