<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipUsageTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class GrantCurrentMembershipUsageTest extends TestCase
{
    use RefreshDatabase;

    private MembershipLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->ledger = app(MembershipLedgerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_grants_the_current_period_once_and_updates_the_cache(): void
    {
        $membership = $this->membership();

        $this->artisan('memberships:grant-current')
            ->expectsOutput('付与 1 件 / スキップ（既存）0 件')
            ->assertSuccessful();

        $this->assertDatabaseHas('membership_usage_transactions', [
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
            'type' => MembershipUsageType::Grant->value,
            'delta' => 4,
            'dedupe_key' => "grant:{$membership->id}:2026-09-01",
        ]);
        $this->assertSame(4, $membership->fresh()->period_available);

        $this->artisan('memberships:grant-current')
            ->expectsOutput('付与 0 件 / スキップ（既存）1 件')
            ->assertSuccessful();

        $this->assertSame(1, $this->grantCount($membership, '2026-09-01'));
        $this->assertSame(4, $this->ledger->available($membership, '2026-09-01'));
    }

    public function test_invoice_paid_grant_and_scheduler_converge_to_one_grant(): void
    {
        $membership = $this->membership();

        // invoice.paid webhook が先に当期 GRANT を記録した状況を模擬する。
        $this->ledger->grant($membership, '2026-09-01', 4);

        $this->artisan('memberships:grant-current')
            ->expectsOutput('付与 0 件 / スキップ（既存）1 件')
            ->assertSuccessful();

        $this->assertSame(1, $this->grantCount($membership, '2026-09-01'));
        $this->assertSame(4, $this->ledger->available($membership, '2026-09-01'));
        $this->assertSame(4, $membership->fresh()->period_available);
    }

    public function test_rollover_grants_the_new_period_without_changing_the_old_period(): void
    {
        $membership = $this->membership();
        $this->ledger->grant($membership, '2026-09-01', 4);
        $this->ledger->append(
            membership: $membership,
            type: MembershipUsageType::Reserve,
            delta: -1,
            dedupeKey: 'reserve:rollover-september',
            periodStart: '2026-09-01',
        );

        $septemberGrant = MembershipUsageTransaction::query()
            ->where('dedupe_key', "grant:{$membership->id}:2026-09-01")
            ->firstOrFail()
            ->getAttributes();
        $septemberAvailable = $this->ledger->available($membership, '2026-09-01');

        $membership->forceFill([
            'current_period_start' => '2026-10-01',
            'current_period_end' => '2026-11-01',
        ])->save();

        $this->artisan('memberships:grant-current')
            ->expectsOutput('付与 1 件 / スキップ（既存）0 件')
            ->assertSuccessful();

        $this->assertSame(1, $this->grantCount($membership, '2026-09-01'));
        $this->assertSame(1, $this->grantCount($membership, '2026-10-01'));
        $this->assertSame($septemberAvailable, $this->ledger->available($membership, '2026-09-01'));
        $this->assertSame(3, $this->ledger->available($membership, '2026-09-01'));
        $this->assertSame(4, $this->ledger->available($membership, '2026-10-01'));
        $this->assertSame(4, $membership->fresh()->period_available);
        $this->assertSame(
            $septemberGrant,
            MembershipUsageTransaction::query()
                ->where('dedupe_key', "grant:{$membership->id}:2026-09-01")
                ->firstOrFail()
                ->getAttributes(),
        );
    }

    public function test_non_bookable_statuses_are_not_granted(): void
    {
        $plan = $this->plan();

        Membership::factory()->paused()->create([
            'membership_plan_id' => $plan->id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        Membership::factory()->pending()->create([
            'membership_plan_id' => $plan->id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        Membership::factory()->canceled()->create([
            'membership_plan_id' => $plan->id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);

        $this->artisan('memberships:grant-current')
            ->expectsOutput('付与 0 件 / スキップ（既存）0 件')
            ->assertSuccessful();

        $this->assertDatabaseCount('membership_usage_transactions', 0);
        $this->assertSame(
            [0, 0, 0],
            Membership::query()->orderBy('id')->pluck('period_available')->all(),
        );
    }

    public function test_dry_run_reports_without_granting(): void
    {
        $membership = $this->membership();

        $this->artisan('memberships:grant-current', ['--dry-run' => true])
            ->expectsOutput("GRANT 予定 membership#{$membership->id} period=2026-09-01 +4")
            ->expectsOutput('付与 1 件 / スキップ（既存）0 件（dry-run: 変更なし）')
            ->assertSuccessful();

        $this->assertDatabaseCount('membership_usage_transactions', 0);
        $this->assertSame(0, $membership->fresh()->period_available);
    }

    private function membership(): Membership
    {
        return Membership::factory()->create([
            'membership_plan_id' => $this->plan()->id,
            'status' => MembershipStatus::Active->value,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
    }

    private function plan(): MembershipPlan
    {
        return MembershipPlan::factory()->create(['usage_count_per_period' => 4]);
    }

    private function grantCount(Membership $membership, string $period): int
    {
        return MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('period_start', $period)
            ->where('type', MembershipUsageType::Grant->value)
            ->count();
    }
}
