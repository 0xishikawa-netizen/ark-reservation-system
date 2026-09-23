<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipUsageType;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\MembershipUsageTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MembershipLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private MembershipLedgerService $ledger;

    private Membership $membership;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(MembershipLedgerService::class);
        $this->period = now()->startOfMonth()->toDateString();
        $this->membership = Membership::factory()->create([
            'current_period_start' => $this->period,
            'current_period_end' => now()->startOfMonth()->addMonth()->toDateString(),
            'period_available' => 0,
        ]);
    }

    public function test_grant_reserve_release_math_and_cache(): void
    {
        $this->ledger->grant($this->membership, $this->period, 4);
        $this->assertSame(4, $this->ledger->available($this->membership));
        $this->assertSame(0, $this->ledger->held($this->membership));
        $this->assertSame(4, $this->ledger->total($this->membership));
        $this->assertSame(4, $this->membership->fresh()->period_available);

        $this->ledger->append($this->membership, MembershipUsageType::Reserve, -1, 'reserve:1', $this->period, reservationId: null);
        $this->assertSame(3, $this->ledger->available($this->membership));
        $this->assertSame(1, $this->ledger->held($this->membership));
        $this->assertSame(4, $this->ledger->total($this->membership));
        $this->assertSame(3, $this->membership->fresh()->period_available);

        $this->ledger->append($this->membership, MembershipUsageType::Release, 1, 'release:1', $this->period, reservationId: null);
        $this->assertSame(4, $this->ledger->available($this->membership));
        $this->assertSame(0, $this->ledger->held($this->membership));
    }

    public function test_dedupe_key_makes_append_idempotent(): void
    {
        $this->ledger->grant($this->membership, $this->period, 4);
        $first = $this->ledger->append($this->membership, MembershipUsageType::Reserve, -1, 'reserve:9', $this->period, reservationId: null);
        $second = $this->ledger->append($this->membership, MembershipUsageType::Reserve, -1, 'reserve:9', $this->period, reservationId: null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('dedupe_key', 'reserve:9')->count());
        $this->assertSame(3, $this->membership->fresh()->period_available);
    }

    public function test_reserve_below_zero_throws_conflict_and_writes_nothing(): void
    {
        // available 0。
        try {
            $this->ledger->append($this->membership, MembershipUsageType::Reserve, -1, 'reserve:x', $this->period, reservationId: null);
            $this->fail('残不足で例外にならなかった');
        } catch (InsufficientMembershipBalanceException) {
            // ok
        }

        $this->assertDatabaseCount('membership_usage_transactions', 0);
        $this->assertSame(0, $this->membership->fresh()->period_available);
    }

    public function test_adjust_validation_and_audit(): void
    {
        $this->ledger->grant($this->membership, $this->period, 4);

        // reason 空 → 422
        try {
            $this->ledger->adjust($this->membership, -1, 'op-a', '   ');
            $this->fail('reason 空が拒否されなかった');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        // delta 0 → 422
        try {
            $this->ledger->adjust($this->membership, 0, 'op-b', '調整');
            $this->fail('delta 0 が拒否されなかった');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('delta', $e->errors());
        }

        // 超過 → 422
        try {
            $this->ledger->adjust($this->membership, -10, 'op-c', '過剰調整');
            $this->fail('過剰調整が拒否されなかった');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('membership', $e->errors());
        }

        $this->ledger->adjust($this->membership, -1, 'op-d', 'テスト調整');
        $this->assertSame(3, $this->ledger->available($this->membership));
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.adjusted']);
    }

    public function test_grant_is_once_per_period(): void
    {
        $this->ledger->grant($this->membership, $this->period, 4);
        $this->ledger->grant($this->membership, $this->period, 4);
        $this->ledger->grant($this->membership, $this->period, 4);

        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('membership_id', $this->membership->id)
            ->where('type', MembershipUsageType::Grant->value)
            ->count());
        $this->assertSame(4, $this->ledger->available($this->membership));
        $this->assertSame(4, $this->membership->fresh()->period_available);
        $this->assertSame(1, AuditLog::query()->where('action', 'membership.granted')->count());
    }

    public function test_writes_to_an_old_period_do_not_touch_current_period_cache(): void
    {
        $oldPeriod = now()->startOfMonth()->subMonth()->toDateString();
        $this->ledger->grant($this->membership, $this->period, 4); // current: available 4, cache 4

        // 旧期への RELEASE（期またぎキャンセルの想定）。
        $this->ledger->append($this->membership, MembershipUsageType::Release, 1, 'release:77', $oldPeriod, reservationId: null);

        $this->assertSame(4, $this->membership->fresh()->period_available, '当期 cache は不変');
        $this->assertSame(4, $this->ledger->available($this->membership), '当期 available は不変');
        $this->assertSame(1, $this->ledger->available($this->membership, $oldPeriod), '旧期には +1 が記録される');
    }

    public function test_recalculate_repairs_a_tampered_cache(): void
    {
        $this->ledger->grant($this->membership, $this->period, 4);
        $this->membership->forceFill(['period_available' => 99])->save();

        $result = $this->ledger->recalculatePeriodAvailable($this->membership);

        $this->assertSame(4, $result);
        $this->assertSame(4, $this->membership->fresh()->period_available);
        // 台帳は不変。
        $this->assertSame(1, MembershipUsageTransaction::query()->count());
    }

    public function test_cache_invariant_holds_after_each_operation(): void
    {
        $this->ledger->grant($this->membership, $this->period, 8);
        $this->ledger->append($this->membership, MembershipUsageType::Reserve, -1, 'reserve:a', $this->period, reservationId: null);
        $this->ledger->append($this->membership, MembershipUsageType::Release, 1, 'release:a', $this->period, reservationId: null);
        $this->ledger->append($this->membership, MembershipUsageType::Consume, -1, 'consume:a', $this->period, reservationId: null);
        $this->ledger->adjust($this->membership, 2, 'op-z', '補正');

        $expected = (int) MembershipUsageTransaction::query()
            ->where('membership_id', $this->membership->id)
            ->where('period_start', $this->period)
            ->sum('delta');

        $this->assertSame($expected, $this->membership->fresh()->period_available);
    }
}
