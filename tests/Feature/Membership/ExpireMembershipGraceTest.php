<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Enums\Membership\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Membership;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ExpireMembershipGraceTest extends TestCase
{
    use DatabaseMigrations;

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->gateway = app(FakeMembershipStripeGateway::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_expired_grace_is_paused_only_after_stripe_confirms_it_has_not_recovered(): void
    {
        $membership = $this->expiredGraceMembership('sub_expired_grace');
        $this->gateway->setSubscription(
            'sub_expired_grace',
            $this->subscription('sub_expired_grace', 'past_due'),
        );

        $this->artisan('memberships:expire-grace')
            ->expectsOutput('paused 1 件 / スキップ（回復・状態変更）0 件')
            ->assertSuccessful();

        $fresh = $membership->fresh();
        $this->assertSame(MembershipStatus::Paused, $fresh->status);
        $this->assertNull($fresh->grace_until);
        $this->assertNotNull($fresh->last_synced_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.paused',
            'entity_id' => (string) $membership->id,
            'summary' => "grace 期限超過で paused membership#{$membership->id}",
        ]);
        $this->assertSame(1, $this->gateway->callCount('retrieve_subscription'));
        $this->assertSame(0, $this->gateway->calls()[0]['transaction_level']);

        $this->artisan('memberships:expire-grace')
            ->expectsOutput('paused 0 件 / スキップ（回復・状態変更）0 件')
            ->assertSuccessful();

        $this->assertSame(1, $this->pausedAuditCount($membership));
        $this->assertSame(1, $this->gateway->callCount('retrieve_subscription'));
    }

    public function test_recovered_grace_becomes_active_and_is_not_paused(): void
    {
        $membership = $this->expiredGraceMembership('sub_recovered_grace');
        $this->gateway->setSubscription(
            'sub_recovered_grace',
            $this->subscription('sub_recovered_grace', 'active', 'paid'),
        );

        $this->artisan('memberships:expire-grace')
            ->expectsOutput('paused 0 件 / スキップ（回復・状態変更）1 件')
            ->assertSuccessful();

        $fresh = $membership->fresh();
        $this->assertSame(MembershipStatus::Active, $fresh->status);
        $this->assertNull($fresh->grace_until);
        $this->assertSame(0, $this->pausedAuditCount($membership));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.activated',
            'entity_id' => (string) $membership->id,
        ]);

        $this->artisan('memberships:expire-grace')->assertSuccessful();

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
        $this->assertSame(1, $this->gateway->callCount('retrieve_subscription'));
        $this->assertSame(0, $this->pausedAuditCount($membership));
    }

    public function test_future_grace_is_not_retrieved_or_changed(): void
    {
        $membership = Membership::factory()->grace()->create([
            'stripe_subscription_id' => 'sub_future_grace',
            'grace_until' => now()->addMinute(),
        ]);

        $this->artisan('memberships:expire-grace')
            ->expectsOutput('paused 0 件 / スキップ（回復・状態変更）0 件')
            ->assertSuccessful();

        $fresh = $membership->fresh();
        $this->assertSame(MembershipStatus::Grace, $fresh->status);
        $this->assertTrue($fresh->grace_until->equalTo(now()->addMinute()));
        $this->assertSame(0, $this->gateway->callCount('retrieve_subscription'));
        $this->assertSame(0, $this->pausedAuditCount($membership));
    }

    public function test_dry_run_reports_without_retrieving_or_changing_state(): void
    {
        $membership = $this->expiredGraceMembership('sub_dry_run_grace');

        $this->artisan('memberships:expire-grace', ['--dry-run' => true])
            ->expectsOutput(
                "paused 判定候補 membership#{$membership->id} grace_until=2026-09-14 12:00:00",
            )
            ->expectsOutput('paused 1 件 / スキップ（回復・状態変更）0 件（dry-run: 変更なし）')
            ->assertSuccessful();

        $fresh = $membership->fresh();
        $this->assertSame(MembershipStatus::Grace, $fresh->status);
        $this->assertTrue($fresh->grace_until->equalTo(now()->subDay()));
        $this->assertSame(0, $this->gateway->callCount('retrieve_subscription'));
        $this->assertSame(0, $this->pausedAuditCount($membership));
    }

    private function expiredGraceMembership(string $subscriptionId): Membership
    {
        return Membership::factory()->grace()->create([
            'stripe_subscription_id' => $subscriptionId,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'grace_until' => now()->subDay(),
        ]);
    }

    private function subscription(
        string $subscriptionId,
        string $stripeStatus,
        string $invoiceStatus = 'open',
    ): SubscriptionResult {
        return new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: $stripeStatus,
            cancelAtPeriodEnd: false,
            currentPeriodStart: '2026-09-01',
            currentPeriodEnd: '2026-10-01',
            latestInvoiceStatus: $invoiceStatus,
            nextPaymentAttempt: '2026-09-14 09:00:00',
        );
    }

    private function pausedAuditCount(Membership $membership): int
    {
        return AuditLog::query()
            ->where('action', 'membership.paused')
            ->where('entity_id', (string) $membership->id)
            ->count();
    }
}
